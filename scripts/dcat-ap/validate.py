"""Independent RDF parsers, graph parity, pinned SHACL and vocabulary membership checks."""

import gzip
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile

from lxml import etree
from pyshacl import validate
from rdflib import Graph, Namespace, RDF, URIRef
from rdflib.compare import isomorphic

ROOT = Path('resources/data/scheme/dcat-ap/3.0.1')
SH = Namespace('http://www.w3.org/ns/shacl#')
DCAT = Namespace('http://www.w3.org/ns/dcat#')
DCT = Namespace('http://purl.org/dc/terms/')
RDF_NS = str(RDF)
manifest = json.loads((ROOT / 'manifest.json').read_text())
for relative, entry in manifest['files'].items():
    raw = (ROOT / relative).read_bytes()
    assert hashlib.sha256(raw).hexdigest() == entry['sha256'], f'Changed pinned asset: {relative}'
    if 'uncompressedSha256' in entry:
        assert hashlib.sha256(gzip.decompress(raw)).hexdigest() == entry['uncompressedSha256']
vocabulary = json.loads((ROOT / 'vocabularies/index.json').read_text())
fixture_root = Path('tests/pest/Fixtures/DcatAp')
fixture_manifest = json.loads((fixture_root / 'manifest.json').read_text())
for relative, entry in fixture_manifest['files'].items():
    assert hashlib.sha256((fixture_root / relative).read_bytes()).hexdigest() == entry['sha256'], f'Changed public fixture: {relative}'
shapes = Graph().parse(ROOT / 'shacl/dcat-ap-SHACL.ttl', format='turtle')
schema = etree.XMLSchema(etree.parse('public/schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd'))
report_path = Path(sys.argv[1])
results = []
for fixture in json.loads(report_path.read_text()):
    document = etree.fromstring(fixture['rdf'].encode(), etree.XMLParser(resolve_entities=False, no_network=True))
    schema.assertValid(document)
    envelope = etree.fromstring(fixture['oai'].encode(), etree.XMLParser(resolve_entities=False, no_network=True))
    embedded = envelope.find('.//{' + RDF_NS + '}RDF')
    assert embedded is not None, fixture['name'] + ': missing OAI metadata'
    schema.assertValid(embedded)
    graph = Graph().parse(data=fixture['rdf'], format='xml')
    oai_graph = Graph().parse(data=etree.tostring(embedded), format='xml')
    assert isomorphic(graph, oai_graph), fixture['name'] + ': transport graphs differ'
    with tempfile.TemporaryDirectory() as temporary:
        path = Path(temporary) / 'export.rdf'
        path.write_text(fixture['rdf'])
        jena = subprocess.run(['riot', '--check', '--strict', '--output=N-TRIPLES', str(path)], capture_output=True, text=True, check=True)
        assert isomorphic(graph, Graph().parse(data=jena.stdout, format='nt')), fixture['name'] + ': parser graphs differ'
    full_conforms, full_report, full_text = validate(graph, shacl_graph=shapes, allow_warnings=True, do_owl_imports=False)
    # Linked external datasets are references, not additional catalog records.
    # Keep the full report. Limit only DatasetShape's target to the datasets
    # actually supplied by this exchange; all other shape constraints remain.
    scoped_shapes = Graph()
    for triple in shapes:
        scoped_shapes.add(triple)
    dataset_shapes = list(scoped_shapes.subjects(SH.targetClass, DCAT.Dataset))
    datasets = set(graph.objects(None, DCAT.dataset))
    assert len(datasets) == 1
    for shape in dataset_shapes:
        scoped_shapes.remove((shape, SH.targetClass, DCAT.Dataset))
        for dataset in datasets:
            scoped_shapes.add((shape, SH.targetNode, dataset))
    conforms, scoped_report, scoped_text = validate(graph, shacl_graph=scoped_shapes, allow_warnings=True, do_owl_imports=False)
    if not conforms:
        print(fixture['name'], scoped_text)
        raise AssertionError('DCAT-AP exchange-scoped SHACL violations')
    for predicate, filename in [(DCT.language, 'eu-language.rdf'), (DCT.accessRights, 'eu-access-right.rdf'), (DCT.format, 'eu-file-types.rdf')]:
        for value in graph.objects(None, predicate):
            assert str(value) in vocabulary['concepts'][filename], (predicate, value)
    themes = {str(value) for value in graph.objects(None, DCAT.theme)}
    for code in ['TECH', 'ENVI']:
        uri = 'http://publications.europa.eu/resource/authority/data-theme/' + code
        assert uri in themes and uri in vocabulary['concepts']['eu-data-theme.rdf']
    for value in graph.objects(None, DCAT.mediaType):
        assert str(value).removeprefix('https://www.iana.org/assignments/media-types/') in vocabulary['mediaTypes']
    # Negative control: a catalog dataset with no description MUST be rejected.
    broken = Graph()
    for triple in graph:
        broken.add(triple)
    for dataset in datasets:
        broken.remove((dataset, DCT.description, None))
    assert not validate(broken, shacl_graph=scoped_shapes, allow_warnings=True, do_owl_imports=False)[0]
    result = {'name': fixture['name'], 'triples': len(graph), 'exchangeConforms': conforms, 'fullGraphConforms': full_conforms, 'fullGraphReport': full_text}
    results.append(result)
    print(f"[dcat-ap] {fixture['name']}: {len(graph)} triples; Jena/RDFLib parity; exchange SHACL passed; full graph conforms: {full_conforms}")
(report_path.parent / 'validation.json').write_text(json.dumps(results, indent=2))
