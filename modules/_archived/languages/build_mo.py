#!/usr/bin/env python3
"""Compile .po -> .mo for WordPress translations."""
import struct, re, os, sys

def parse_po(filepath):
    entries = []
    with open(filepath, encoding='utf-8') as f:
        content = f.read()

    blocks = re.split(r'\n\n+', content.strip())
    for block in blocks:
        msgid_match  = re.search(r'^msgid\s+(.+?)(?=\nmsgstr|\Z)', block, re.MULTILINE | re.DOTALL)
        msgstr_match = re.search(r'^msgstr\s+(.+?)$', block, re.MULTILINE | re.DOTALL)
        if not msgid_match or not msgstr_match:
            continue
        def extract(s):
            parts = re.findall(r'"((?:[^"\\]|\\.)*)"', s)
            return ''.join(parts).replace('\\n', '\n').replace('\\t', '\t').replace('\\"', '"')
        msgid  = extract(msgid_match.group(1))
        msgstr = extract(msgstr_match.group(1))
        entries.append((msgid, msgstr))
    return entries

def build_mo(catalog):
    MAGIC = 0x950412de
    catalog_sorted = sorted(catalog)
    N = len(catalog_sorted)

    offsets = []
    ids  = b''
    strs = b''

    for msgid, msgstr in catalog_sorted:
        ids_bytes  = msgid.encode('utf-8')
        strs_bytes = msgstr.encode('utf-8')
        offsets.append((len(ids), len(ids_bytes), len(strs), len(strs_bytes)))
        ids  += ids_bytes  + b'\x00'
        strs += strs_bytes + b'\x00'

    header_size      = 7 * 4
    offsets_id_start = header_size
    offsets_st_start = offsets_id_start + N * 8
    hash_offset      = offsets_st_start + N * 8   # hash table size = 0
    ids_start        = hash_offset
    strs_start       = ids_start + len(ids)

    out = struct.pack('<IIIIIII',
        MAGIC, 0, N,
        offsets_id_start, offsets_st_start,
        0, hash_offset,
    )
    for id_off, id_len, st_off, st_len in offsets:
        out += struct.pack('<II', id_len, ids_start + id_off)
    for id_off, id_len, st_off, st_len in offsets:
        out += struct.pack('<II', st_len, strs_start + st_off)
    out += ids
    out += strs
    return out

po_path = os.path.join(os.path.dirname(__file__), 'bizcity-webchat-vi_VN.po')
mo_path = po_path.replace('.po', '.mo')

entries = parse_po(po_path)
catalog = [(i, m) for i, m in entries if i != '' and m != '']

print(f'Parsed {len(entries)} entries, {len(catalog)} translated strings')

mo_data = build_mo(catalog)
with open(mo_path, 'wb') as f:
    f.write(mo_data)

print(f'Written : {mo_path}')
print(f'Size    : {len(mo_data)} bytes')
