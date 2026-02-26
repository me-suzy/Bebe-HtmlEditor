"""
Converteste fisiere HTML din orice encoding cu BOM -> UTF-8 fara BOM.

Utilizare:
    python convert-to-utf8.py
    python convert-to-utf8.py --folder "e:\Carte\BB\17 - Site Leadership\Principal\ro"
    python convert-to-utf8.py --folder "..." --recurse
    python convert-to-utf8.py --folder "..." --dry-run
"""

import os
import sys
import argparse

# ============================================================
#  SCHIMBA AICI folderul pe care vrei sa-l procesezi:
# ============================================================
DEFAULT_FOLDER = r'd:\Teste cursor\docs'
# ============================================================

# BOM signatures: (bom_bytes, encoding_name, label)
BOMS = [
    (b'\xff\xfe\x00\x00', 'utf-32-le', 'UTF-32 LE BOM'),
    (b'\x00\x00\xfe\xff', 'utf-32-be', 'UTF-32 BE BOM'),
    (b'\xef\xbb\xbf',     'utf-8',     'UTF-8 BOM'),
    (b'\xff\xfe',         'utf-16-le', 'UTF-16 LE BOM'),
    (b'\xfe\xff',         'utf-16-be', 'UTF-16 BE BOM'),
]

def detect_bom(data: bytes):
    """Returneaza (bom_len, encoding, label) sau (0, None, None) daca nu are BOM."""
    for bom, encoding, label in BOMS:
        if data.startswith(bom):
            return len(bom), encoding, label
    return 0, None, None

def convert_file(path: str, dry_run: bool) -> str:
    """
    Returneaza:
        'converted' - a fost convertit
        'skipped'   - nu avea BOM
        'error'     - eroare
    """
    try:
        with open(path, 'rb') as f:
            data = f.read()

        bom_len, encoding, label = detect_bom(data)

        if bom_len == 0:
            return 'skipped'

        text = data[bom_len:].decode(encoding)

        if not dry_run:
            with open(path, 'w', encoding='utf-8', newline='') as f:
                f.write(text)

        return ('dry', label) if dry_run else ('converted', label)

    except Exception as e:
        return ('error', str(e))

def main():
    parser = argparse.ArgumentParser(description='Converteste HTML-uri din BOM la UTF-8.')
    parser.add_argument('--folder',  default=DEFAULT_FOLDER, help='Folderul de procesat')
    parser.add_argument('--filter',  default='*.html', help='Extensie fisiere (default: *.html)')
    parser.add_argument('--recurse', action='store_true', help='Include subfoldere')
    parser.add_argument('--dry-run', action='store_true', help='Afiseaza ce ar face, fara modificari')
    args = parser.parse_args()

    ext = args.filter.lstrip('*')  # '*.html' -> '.html'
    folder = args.folder

    if not os.path.isdir(folder):
        print(f'Eroare: folderul nu exista: {folder}')
        sys.exit(1)

    # Colecteaza fisierele
    if args.recurse:
        files = [
            os.path.join(root, f)
            for root, _, fs in os.walk(folder)
            for f in fs if f.lower().endswith(ext)
        ]
    else:
        files = [
            os.path.join(folder, f)
            for f in os.listdir(folder)
            if f.lower().endswith(ext) and os.path.isfile(os.path.join(folder, f))
        ]

    print(f'\nFolder : {folder}')
    print(f'Fisiere: {len(files)}')
    if args.dry_run:
        print('*** DRY RUN - nicio modificare ***')
    print()

    converted = skipped = errors = 0

    for path in sorted(files):
        result = convert_file(path, args.dry_run)

        if isinstance(result, tuple):
            status, info = result
        else:
            status = result
            info = ''

        name = os.path.basename(path)

        if status == 'converted':
            converted += 1
            print(f'  \033[32m[OK]  {info} -> UTF-8 : {name}\033[0m')
        elif status == 'dry':
            converted += 1
            print(f'  \033[36m[DRY] {info} -> UTF-8 : {name}\033[0m')
        elif status == 'skipped':
            skipped += 1
        elif status == 'error':
            errors += 1
            print(f'  \033[31m[ERR] {name} : {info}\033[0m')

    print()
    print('=' * 48)
    if args.dry_run:
        print(f'De convertit : {converted}  (DRY RUN, nicio modificare)')
    else:
        print(f'Convertite   : {converted}')
    print(f'Sarite       : {skipped}  (deja UTF-8 fara BOM)')
    print(f'Erori        : {errors}')
    print('=' * 48)
    print()

if __name__ == '__main__':
    main()
