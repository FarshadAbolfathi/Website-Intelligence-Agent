#!/usr/bin/env python3
"""Compile .po files to .mo using only the standard library, so building needs no gettext tools."""

from __future__ import annotations

import ast
import re
import struct
import sys
from pathlib import Path


def parse_po(path: Path) -> dict[str, str]:
    entries: dict[str, str] = {}
    msgid, msgstr, field = None, None, None

    def flush() -> None:
        if msgid is not None and msgstr:
            entries[msgid] = msgstr

    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line.startswith("msgid "):
            flush()
            msgid, msgstr, field = ast.literal_eval(line[6:]), "", "id"
        elif line.startswith("msgstr "):
            msgstr, field = ast.literal_eval(line[7:]), "str"
        elif line.startswith('"'):
            if field == "id":
                msgid += ast.literal_eval(line)
            elif field == "str":
                msgstr += ast.literal_eval(line)
    flush()
    return entries


def write_mo(entries: dict[str, str], path: Path) -> None:
    keys = sorted(entries)
    ids = [k.encode() for k in keys]
    strs = [entries[k].encode() for k in keys]
    header = 7 * 4
    start_ids = header
    start_strs = start_ids + 8 * len(keys)
    data_start = start_strs + 8 * len(keys)
    id_table, str_table, data = [], [], b""
    for item in ids:
        id_table.append((len(item), data_start + len(data)))
        data += item + b"\0"
    for item in strs:
        str_table.append((len(item), data_start + len(data)))
        data += item + b"\0"
    out = struct.pack("<7I", 0x950412DE, 0, len(keys), start_ids, start_strs, 0, data_start)
    for length, offset in id_table + str_table:
        out += struct.pack("<2I", length, offset)
    path.write_bytes(out + data)


def main(folder: str) -> None:
    for po in Path(folder).glob("*.po"):
        entries = parse_po(po)
        write_mo(entries, po.with_suffix(".mo"))
        print(f"{po.name}: {len(entries) - (1 if '' in entries else 0)} strings")


if __name__ == "__main__":
    if len(sys.argv) != 2 or not re.search(r".", sys.argv[1]):
        sys.exit("usage: compile_mo.py LANGUAGES_DIR")
    main(sys.argv[1])
