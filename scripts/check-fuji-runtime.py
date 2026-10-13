"""Run inside the built F-UJI image to verify its runtime without installers."""

import importlib
import importlib.util
import os
import tempfile

from playwright.sync_api import sync_playwright

for installer in ("pip", "ensurepip"):
    if importlib.util.find_spec(installer) is not None:
        raise RuntimeError(f"Build-only installer remains in the runtime image: {installer}")

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

print("F-UJI dependencies and Chromium work without pip or ensurepip.")
