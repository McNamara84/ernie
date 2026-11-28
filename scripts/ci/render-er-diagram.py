"""Render the ER diagram in one of the database Markdown files using Docker."""

import argparse
import re
import subprocess
from collections import Counter
from pathlib import Path
from xml.etree import ElementTree

HERE = Path(__file__).resolve().parent
DATABASE = HERE.parents[1] / "database"
MERMAID_IMAGE = (
    "ghcr.io/mermaid-js/mermaid-cli/mermaid-cli:12.0.1"
    "@sha256:18938e53497376d524e04b1fc0bf7b6d61a491ce9ff7032bb2855d57155acb10"
)
PLANTUML_IMAGE = (
    "ghcr.io/plantuml/plantuml:1.2026.10"
    "@sha256:2cf2f097652701319e29b9e6a809e68d4ddb06fbeeb696705c097da8fa6d0507"
)
TABLE_DECLARATIONS = {
    "mermaid": r"^[ \t]*([\w-]+)[ \t]*\{[ \t]*$",
    "plantuml": r'^[ \t]*entity[ \t]+(?:"[^"]+"[ \t]+as[ \t]+)?"?([\w-]+)"?[ \t]*\{',
}
CROW_FOOT_RELATION = r"^[ \t]*([\w-]+)[ \t]+[|o{}]+(?:--|\.\.)[|o{}]+[ \t]+([\w-]+)"


def verify_svg(renderer, diagram, svg_bytes):
    svg = ElementTree.fromstring(svg_bytes)
    if svg.tag != "{http://www.w3.org/2000/svg}svg":
        raise ValueError(f"{renderer} did not produce an SVG")

    tables = set(re.findall(TABLE_DECLARATIONS[renderer], diagram, re.MULTILINE))
    if not tables:
        raise ValueError(f"No table declarations in the {renderer} source")

    links = []
    if renderer == "mermaid":
        labels = svg.findall(".//{*}g[@class='label name']")
        names = {"".join(label.itertext()) for label in labels}
        for edge in svg.findall(".//{*}path[@data-edge='true']"):
            edge_id = edge.attrib["data-id"]
            # Mermaid embeds both entity names and their numeric IDs in each edge ID.
            endpoints = re.fullmatch(r"id_entity-([\w-]+)-\d+_entity-([\w-]+)-\d+_\d+", edge_id)
            if not endpoints:
                raise ValueError(f"Unrecognized Mermaid relationship ID: {edge_id}")
            links.append(endpoints.groups())
    else:
        entities = {
            node.attrib["id"]: node.attrib["data-qualified-name"]
            for node in svg.findall(".//{*}g[@class='entity']")
        }
        names = set(entities.values())
        for edge in svg.findall(".//{*}g[@class='link']"):
            first = entities[edge.attrib["data-entity-1"]]
            second = entities[edge.attrib["data-entity-2"]]
            # PlantUML also emits nodes and links for notes attached to tables.
            if first in tables and second in tables:
                links.append((first, second))

    if tables - names:
        raise ValueError(f"Missing {renderer} tables: {sorted(tables - names)}")
    expected = Counter(re.findall(CROW_FOOT_RELATION, diagram, re.MULTILINE))
    actual = Counter(links)
    if expected != actual:
        raise ValueError(
            f"{renderer} relationships differ: missing {dict(expected - actual)}, "
            f"unexpected {dict(actual - expected)}"
        )
    return len(tables), len(links)


def render(renderer, output_dir):
    if renderer == "mermaid":
        source = DATABASE / "er-diagram.md"
        config = HERE / "mermaid-er-config.json"
        command = [
            "--volume", f"{config}:/mermaid-config.json:ro", MERMAID_IMAGE,
            "-i", "-", "-o", "-", "-e", "svg", "-c", "/mermaid-config.json", "-q",
        ]
    else:
        source = DATABASE / "er-diagram-plantuml.md"
        command = [PLANTUML_IMAGE, "-charset", "UTF-8", "-tsvg", "-pipe", "-nometadata"]

    markdown = source.read_text(encoding="utf-8")
    fence = rf"^```{renderer}[ \t]*\n(.*?)^```[ \t]*$"
    blocks = re.findall(fence, markdown, re.MULTILINE | re.DOTALL)
    if len(blocks) != 1 or not blocks[0].strip():
        raise ValueError(f"Expected one nonempty {renderer} code block in {source}")

    diagram = blocks[0]
    result = subprocess.run(
        ["docker", "run", "--rm", "--network", "none", "-i", *command],
        input=diagram.encode("utf-8"),
        stdout=subprocess.PIPE,
        check=True,
    )
    table_count, relationship_count = verify_svg(renderer, diagram, result.stdout)
    output_dir.mkdir(parents=True, exist_ok=True)
    output = output_dir / f"er-diagram-{renderer}.svg"
    output.write_bytes(result.stdout)
    print(f"Rendered {output} with {table_count} tables and {relationship_count} relationships.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("renderer", choices=["mermaid", "plantuml"])
    parser.add_argument("output_dir", type=Path)
    args = parser.parse_args()
    render(args.renderer, args.output_dir)
