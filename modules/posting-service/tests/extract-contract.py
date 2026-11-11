"""Extract the delivery schema graph from the agreed OpenAPI; requires PyYAML.

Usage: python extract-contract.py /path/to/posting-service.v1.yaml
The input hash is deliberately pinned; updating the contract requires review.
"""
import hashlib
import json
import pathlib
import sys
import yaml

COMMIT = "c81e5d37d1892406f5d0919cd446a17d77bc028d"
SHA256 = "c0e183ad1de390a0e0f5cdf79784e73dab681870ca433cac2b1c81547dd6050d"
raw = pathlib.Path(sys.argv[1]).read_bytes().replace(b"\r\n", b"\n")
assert hashlib.sha256(raw).hexdigest() == SHA256, "Review and pin the new API release first"
class Loader(yaml.SafeLoader):
    """YAML 1.2 lets an anchor be redefined (the OpenAPI bundler reuses names); an alias
    refers to the latest definition. PyYAML refuses that, so forget the earlier one."""
    def compose_node(self, parent, index):
        event = self.peek_event()
        if not isinstance(event, yaml.events.AliasEvent) and getattr(event, "anchor", None) is not None:
            self.anchors.pop(event.anchor, None)
        return super().compose_node(parent, index)

all_schemas = yaml.load(raw, Loader=Loader)["components"]["schemas"]
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
