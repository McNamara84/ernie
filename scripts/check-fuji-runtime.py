"""Run inside the built F-UJI image to verify its runtime without installers."""

import importlib
import importlib.util
import os
import ssl
import tempfile

import certifi
import extruct
from bs4 import BeautifulSoup
from playwright.sync_api import sync_playwright
from uritools import urisplit, uriunsplit

# GHSA-6q48-6c7w-9gw2: the compromised CA must no longer be trusted by F-UJI.
certificate_store = ssl.create_default_context(cafile=certifi.where())
for certificate in certificate_store.get_ca_certs():
    if any(
        name == "commonName" and value == "emSign ECC Root CA - G3"
        for relative_name in certificate["subject"]
        for name, value in relative_name
    ):
        raise RuntimeError("F-UJI still trusts the compromised emSign ECC Root CA - G3")

for installer in ("pip", "ensurepip"):
    if importlib.util.find_spec(installer) is not None:
        raise RuntimeError(f"Build-only installer remains in the runtime image: {installer}")

# Exercise HTML extraction after updating the selector engine and URI library.
landing_page = """<!doctype html><html><head>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"Dataset","name":"Runtime dataset"}
</script></head><body>
<article itemscope itemtype="https://schema.org/Dataset">
<h1 itemprop="name">Runtime dataset</h1>
</article></body></html>"""
dataset_name = BeautifulSoup(landing_page, "lxml").select_one(
    'article[itemtype="https://schema.org/Dataset"] [itemprop="name"]'
)
if dataset_name is None or dataset_name.get_text() != "Runtime dataset":
    raise RuntimeError("F-UJI HTML selectors could not find dataset metadata")
metadata = extruct.extract(
    landing_page,
    base_url="https://example.test/dataset",
    syntaxes=["json-ld", "microdata"],
    uniform=True,
)
for syntax in ("json-ld", "microdata"):
    if metadata[syntax][0].get("name") != "Runtime dataset":
        raise RuntimeError(f"F-UJI could not extract {syntax} dataset metadata")
dataset_url = "https://example.test/dataset?id=123#data"
if uriunsplit(urisplit(dataset_url)) != dataset_url:
    raise RuntimeError("F-UJI URI handling changed the dataset URL")

with tempfile.TemporaryDirectory(prefix="fuji-runtime-") as log_directory:
    os.environ["TIKA_LOG_PATH"] = log_directory
    for module in ("fuji_server.controllers.fair_object_controller", "setuptools", "tika.parser"):
        importlib.import_module(module)

    with sync_playwright() as playwright:
        with playwright.chromium.launch() as browser:
            page = browser.new_page()
            page.set_content("<title>F-UJI runtime check</title>")
            if page.title() != "F-UJI runtime check":
                raise RuntimeError("Chromium could not render the runtime check page")

print("F-UJI metadata extraction, dependencies and Chromium work without pip or ensurepip.")
