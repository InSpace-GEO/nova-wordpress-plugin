"""Extract the delivery schema graph from the agreed OpenAPI; requires PyYAML.

Usage: python extract-contract.py /path/to/posting-service.v1.yaml
The input hash is deliberately pinned; updating the contract requires review.
"""
import hashlib
import json
import pathlib
import sys
import yaml

COMMIT = "1bd3fb94e6360a8dc9c5247917bcd12a2aac8271"
SHA256 = "80432e985cab8d142d381560aef41e9635c63a696bc293d2a8f10a305813db98"
raw = pathlib.Path(sys.argv[1]).read_bytes().replace(b"\r\n", b"\n")
assert hashlib.sha256(raw).hexdigest() == SHA256, "Review and pin the new API release first"
all_schemas = yaml.safe_load(raw)["components"]["schemas"]
selected = {}

def visit(name):
    if name in selected:
        return
    selected[name] = all_schemas[name]
    scan(selected[name])

def scan(value):
    if isinstance(value, dict):
        if "$ref" in value:
            assert value["$ref"].startswith("#/components/schemas/")
            visit(value["$ref"].split("/")[-1])
        for child in value.values():
            scan(child)
    elif isinstance(value, list):
        for child in value:
            scan(child)

for root in ["Delivery", "DeliverySnapshot", "DeliveryPage", "ConnectorEventInput", "ConnectorEventAccepted",
             "DestinationAdaptationStatus", "AdaptationDeliveryV2",
             "PublishingTemplate", "PublishingTemplateInput", "PublishingTemplateDefaults", "PublishingTemplateDefaultsInput",
             "PublishingPageSetting", "PublishingPageSettingInput"]:
    visit(root)
output = pathlib.Path(__file__).parents[1] / "contracts" / "delivery-v1.json"
output.parent.mkdir(exist_ok=True)
output.write_text(json.dumps({"source_commit": COMMIT, "openapi_sha256": SHA256, "schemas": selected},
                            ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(f"Extracted {len(selected)} schemas: {output.name}")
