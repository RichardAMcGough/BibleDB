"""Quick smoke test for gr_converter (no vision/API key required)."""
import sys
sys.path.insert(0, ".")
import gr_converter as g

pages = g.list_gr_pages()
print(f"Found {len(pages)} GR pages. First 10: {pages[:10]}")

# Test parse quality across three page types
for num in [100, 888, 1000]:
    print(f"\n{'='*55}")
    print(f"GR_{num}")
    print("="*55)
    note = g.convert_gr_page(num, extract_unicode=False)
    print(f"Title    : {note['title']}")
    print(f"Citation : {note['citation_raw']}")
    print(f"Book     : {note['book_code']}  Ch: {note['chapter']}  V: {note['verse']}")
    print(f"Text len : {len(note['note_text'])} chars")
    # Show first word entry to verify tblNVT parsing
    import re
    first_entry = re.search(r"<p class='gr-desc'>(.*?)</p>", note["note_text"])
    if first_entry:
        print(f"1st entry: {first_entry.group(1)}")

# Test DB insert (dry run)
print("\n\n=== Dry-run DB insert for GR_100 ===")
try:
    note = g.convert_gr_page(100, extract_unicode=False)
    # Simulate what insert_note would do but don't actually insert
    print("note ready, fields:")
    print(f"  user_id={g.DEFAULT_USER_ID}  username={g.DEFAULT_USERNAME!r}")
    print(f"  book_code={note['book_code']!r}  chapter={note['chapter']}  verse={note['verse']}")
    print(f"  title={note['title']!r}")
    print(f"  gem_std={note['gem_std']}")
    print(f"  note_text length: {len(note['note_text'])} chars")
    print("Dry run OK")
except Exception as e:
    print(f"ERROR: {e}")
