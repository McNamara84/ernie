"""Build the runtime lookup index from the checked-in authoritative snapshots (no network)."""

import gzip
import hashlib
import json
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2] / 'resources/data/scheme/dcat-ap/3.0.1'
VOCAB = ROOT / 'vocabularies'
NS = {'rdf': 'http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'skos': 'http://www.w3.org/2004/02/skos/core#', 'iana': 'http://www.iana.org/assignments'}
SOURCES = {
    'iana-media-types.xml': 'https://www.iana.org/assignments/media-types/media-types.xml',
    'eu-file-types.rdf': 'https://op.europa.eu/o/opportal-service/euvoc-download-handler?cellarURI=http://publications.europa.eu/resource/distribution/file-type/20260923-0/rdf/skos_core/filetypes-skos.rdf&fileName=filetypes-skos.rdf',
    'eu-language.rdf': 'https://op.europa.eu/o/opportal-service/euvoc-download-handler?cellarURI=http://publications.europa.eu/resource/distribution/language/20260923-0/rdf/skos_core/languages-skos.rdf&fileName=languages-skos.rdf',
    'eu-dataset-type.rdf': 'https://op.europa.eu/o/opportal-service/euvoc-download-handler?cellarURI=http://publications.europa.eu/resource/distribution/dataset-type/20260923-0/rdf/skos_core/dataset-types-skos.rdf&fileName=dataset-types-skos.rdf',
    'eu-access-right.rdf': 'https://publications.europa.eu/resource/authority/access-right',
    'eu-data-theme.rdf': 'https://publications.europa.eu/resource/authority/data-theme',
}
index = {'mediaTypes': [], 'fileTypes': {}, 'languages': {}, 'concepts': {}}
manifest = {'retrieved': '2026-11-09', 'dcatApVersion': '3.0.1', 'upstreamCommit': '4470b8ea907b7eb9ec55ab22befd1ecd2210ff32', 'files': {}}
for filename, source in SOURCES.items():
    raw_path = VOCAB / filename
    archive = VOCAB / (filename + '.gz')
    raw = raw_path.read_bytes() if raw_path.exists() else gzip.decompress(archive.read_bytes())
    document = ET.fromstring(raw)
    if filename.startswith('iana'):
        index['mediaTypes'] = sorted({node.text.strip().lower() for node in document.findall('.//iana:record/iana:file', NS) if node.text})
    else:
        concepts = []
        for node in document:
            uri = node.get('{' + NS['rdf'] + '}about', '')
            if not uri.startswith('http://publications.europa.eu/resource/authority/'):
                continue
            if node.find('skos:inScheme', NS) is not None or node.tag == '{' + NS['skos'] + '}Concept':
                concepts.append(uri)
            for notation in node.findall('skos:notation', NS):
                datatype = notation.get('{' + NS['rdf'] + '}datatype', '')
                value = (notation.text or '').strip().lower()
                if filename == 'eu-file-types.rdf' and datatype.endswith('_MT'):
                    index['fileTypes'].setdefault(value, []).append(uri)
                if filename == 'eu-language.rdf' and '#ISO_639' in datatype and value:
                    index['languages'][value] = uri
        index['concepts'][filename] = sorted(set(concepts))
    archive.write_bytes(gzip.compress(raw, mtime=0))
    if raw_path.exists():
        raw_path.unlink()
    manifest['files']['vocabularies/' + archive.name] = {'source': source, 'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(), 'uncompressedSha256': hashlib.sha256(raw).hexdigest()}
index['fileTypes'] = {mime: sorted(set(uris)) for mime, uris in index['fileTypes'].items()}
index_path = VOCAB / 'index.json'
index_path.write_text(json.dumps(index, ensure_ascii=False, sort_keys=True, indent=2) + '\n', encoding='utf-8')
manifest['files']['vocabularies/index.json'] = {'source': 'Derived by scripts/dcat-ap/build-vocabulary-index.py', 'sha256': hashlib.sha256(index_path.read_bytes()).hexdigest()}
for path in sorted(ROOT.rglob('*.ttl')):
    relative = path.relative_to(ROOT).as_posix()
    manifest['files'][relative] = {'source': 'https://raw.githubusercontent.com/SEMICeu/DCAT-AP/' + manifest['upstreamCommit'] + '/releases/3.0.1/' + relative, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
(ROOT / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')
print('Indexed', len(index['mediaTypes']), 'IANA types and', len(index['languages']), 'language codes.')
