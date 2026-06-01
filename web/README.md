# Bible Browser — PHP web UI

A drop-in PHP page that displays a full interlinear view of any verse in
the `stepbible` database. No frameworks, no dependencies beyond PHP and
the PDO MySQL driver.

## Files

| File                | Purpose                                                |
|---------------------|--------------------------------------------------------|
| `index.php`         | Main page — verse display + AJAX API for dropdowns     |
| `db.php`            | PDO connection helper + query functions                |
| `style.css`         | Page styling                                           |
| `config.sample.php` | Sample DB credentials — copy to `config.php` and edit  |

## Setup

### 1. PHP requirements

Need PHP 7.4+ with the `pdo_mysql` extension. On Windows:

```
php -m | findstr pdo_mysql
```

Should print `pdo_mysql`. If not, enable it in your `php.ini`
(`extension=pdo_mysql` line, then restart your web server).

### 2. Configure database credentials

```cmd
copy config.sample.php config.php
notepad config.php
```

Set host / user / password / database to match your local MariaDB
setup. The default database name is `stepbible`.

### 3. Serve the folder

Three options, pick whichever you have:

**Built-in PHP dev server** (zero install — just need `php` on PATH):
```cmd
cd "C:\Work\Resurrected\Claude\BibleDB\Bible Database\web"
php -S localhost:8080
```
Then open <http://localhost:8080> in your browser.

**Apache / XAMPP / WAMP**: copy the `web/` folder into your `htdocs`
(or symlink it), then open `http://localhost/web/`.

**IIS**: configure a virtual directory pointing at this folder.

## Using the page

Two ways to navigate to a verse:

* **Dropdowns**: pick Book → Chapter → Verse. Chapter and Verse lists
  refresh automatically when you change Book / Chapter (via the JSON
  API at `?api=chapters&book=…` / `?api=verses&book=…&chapter=…`).
* **Reference text box**: type a free-form reference like `Jhn 3:16`,
  `1 Cor 13:13`, `Gen 1.1`, `Psalm 23:1`. Common abbreviations
  (Matt, Mt, Mk, Jn, 1Cor, Phlm, Rev, Ps, Song, etc.) are recognized.

The verse view shows:

1. **Assembled text** — the original-language line and the assembled
   English line (Hebrew rendered RTL).
2. **Word-by-word table** — one row per word with position, original
   text, transliteration (Hebrew only), English gloss, Strong's number,
   morphology code, and source type.
3. **Per-word detail** — directly under each word: the editions that
   contain it, alt Strong's, lemma/gloss, sub-meaning, conjoin links,
   Hebrew morpheme breakdown, and any textual variants (with the
   editions that support each variant).
4. **Source verse-summary blocks** — collapsible at the bottom, showing
   the verbatim `#_Translation` / `#_Word=Grammar` lines from the
   original STEPBible files for round-trip reference.

## Next steps

This first cut is reference lookup only. Future iterations can add:

* Strong's-number concordance (`?strongs=G2316`)
* English keyword search across `verse.text_english`
* Filter by edition (e.g. show only words present in NA28 but missing
  from TR)
* Filter by variant kind / book range
