import base64
import io
import re
import subprocess
import tarfile
import unittest
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


class PackageTest(unittest.TestCase):
    def test_install_payload_and_shell(self):
        subprocess.run(['python3', str(ROOT/'scripts/build.py')], check=True, capture_output=True)
        plugin = ET.parse(ROOT/'dist/disknap.plg').getroot()
        self.assertEqual(plugin.attrib['min'], '7.2.0')
        scripts = [node.text for node in plugin.findall('FILE/INLINE')]
        for script in scripts:
            subprocess.run(['bash', '-n'], input=script, text=True, check=True)
        encoded = re.search(r"DISKNAP_PAYLOAD'\n(.*?)\nDISKNAP_PAYLOAD", scripts[0], re.S).group(1)
        with tarfile.open(fileobj=io.BytesIO(base64.b64decode(encoded)), mode='r:gz') as archive:
            self.assertEqual(set(archive.getnames()), {str(p.relative_to(ROOT/'source')) for p in (ROOT/'source').rglob('*') if p.is_file()})
            for member in archive.getmembers():
                self.assertEqual(archive.extractfile(member).read(), (ROOT/'source'/member.name).read_bytes())
            self.assertNotIn('event/stopping_svcs', archive.getnames())
        self.assertIn("if ! crontab -c /etc/cron.d -l | grep -Fq '/plugins/disknap/collect.php'", scripts[0])
        self.assertIn('update_cron', scripts[1])
        self.assertNotIn('rm -rf /boot', scripts[1])


if __name__ == '__main__':
    unittest.main()
