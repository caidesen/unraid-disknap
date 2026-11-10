#!/usr/bin/env python3
"""生成源码内嵌的本地 PLG 安装包。"""
import base64
import io
import tarfile
from pathlib import Path
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]


def build():
    payload = io.BytesIO()
    with tarfile.open(fileobj=payload, mode='w:gz') as archive:
        for path in sorted((ROOT / 'source').rglob('*')):
            if not path.is_file():
                continue
            entry = archive.gettarinfo(str(path), arcname=str(path.relative_to(ROOT / 'source')))
            entry.uid = entry.gid = 0
            entry.uname = entry.gname = 'root'
            entry.mode = 0o755 if path.parent.name == 'event' else 0o644
            with path.open('rb') as stream:
                archive.addfile(entry, stream)
    encoded = base64.b64encode(payload.getvalue()).decode()
    install = '''set -euo pipefail
[ -x /usr/bin/php ] || { echo "DiskNap: PHP unavailable"; exit 1; }
/usr/bin/php -r 'exit(class_exists("SQLite3") ? 0 : 1);' || { echo "DiskNap: SQLite3 unavailable"; exit 1; }
work=$(mktemp -d /tmp/disknap.XXXXXX)
trap 'rm -rf "$work"' EXIT
base64 -d > "$work/source.tgz" <<'DISKNAP_PAYLOAD'
''' + encoded + '''
DISKNAP_PAYLOAD
mkdir -p /usr/local/emhttp/plugins/disknap /boot/config/plugins/disknap /var/local/disknap
rm -f /usr/local/emhttp/plugins/disknap/event/stopping_svcs
tar -xzf "$work/source.tgz" -C /usr/local/emhttp/plugins/disknap
printf '%s\\n' '* * * * * /usr/bin/php /usr/local/emhttp/plugins/disknap/collect.php' > /boot/config/plugins/disknap/disknap.cron
/usr/local/sbin/update_cron
# 首次安装此时尚未在 /var/log/plugins 注册，补入本插件任务。
if ! crontab -c /etc/cron.d -l | grep -Fq '/plugins/disknap/collect.php'; then
  { crontab -c /etc/cron.d -l; cat /boot/config/plugins/disknap/disknap.cron; } | crontab -c /etc/cron.d -
fi
/usr/bin/php /usr/local/emhttp/plugins/disknap/collect.php || echo "DiskNap: waiting for disk state; check syslog"
'''
    remove = '''set -euo pipefail
rm -f /boot/config/plugins/disknap/disknap.cron
/usr/local/sbin/update_cron
rm -rf /usr/local/emhttp/plugins/disknap
echo "DiskNap removed; history retained at configured database path; config retained in /boot/config/plugins/disknap"
'''
    plugin = ET.Element('PLUGIN', name='disknap', author='DiskNap', version='2026.11.10f', min='7.2.0')
    ET.SubElement(plugin, 'CHANGES').text = '修复通知失败重试，清理旧统计字段与多余查询。'
    for script, method in [(install, None), (remove, 'remove')]:
        attrs = {'Run':'/bin/bash'}
        if method:
            attrs['Method'] = method
        ET.SubElement(ET.SubElement(plugin, 'FILE', attrs), 'INLINE').text = script
    output = ROOT / 'dist/disknap.plg'
    output.parent.mkdir(exist_ok=True)
    ET.ElementTree(plugin).write(output, encoding='utf-8', xml_declaration=True)
    print(output)


if __name__ == '__main__':
    build()
