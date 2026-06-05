import sys; sys.path.insert(0, '.')
from shared.db import execute, query

db = "biblewheel_research"

# ── UPDATE ID 14: richer passage-level analysis ──
execute(
    """UPDATE allusions SET
        source_range=?, target_ref=?, target_range=?,
        terms_score=?, themes_score=?, thesis_score=?, total_score=?,
        context=?, explanation=?, tags=?
       WHERE id=14""",
    (
        4, 'Rom 9:19', 5,
        9, 8, 9, 26,
        'Isa 45:9-13 // Rom 9:19-24 — potter/clay sovereignty cluster',
        ('Near-verbatim LXX quotation: Isa 45:9 LXX "mu erei to plasma to plasanti" / '
         'Rom 9:20 matches word-for-word. Paul extends Isa 45:9-13 through Rom 9:21-24: '
         'the potter/clay image grounds God right to make vessels for honour or dishonour; '
         'Isa 45:11-12 (I made the earth, I created man on it) provides the creator-sovereignty '
         'context Paul extends to election; Isa 45:13 (raised up in righteousness, without price) '
         'echoes grace-not-works. This is the centerpiece of Romans 9 theodicy — without '
         'Isa 45:9-13, the answer to "why does God still find fault?" collapses. '
         'Grade AAA+: highest confidence, explicit LXX quotation plus extended passage dependence.'),
        'grok-validated,isa45,romans9,potter-clay,sovereignty,election,explicit-quotation,lxx,theodicy',
    ), database=db
)
print("Updated ID 14: Isa 45:9-13 // Rom 9:19-24  (score 26, AAA+)")

rows = [
    # 1 — Isa 45:23 // Rom 14:11 — every knee shall bow
    ('Isa 45:23', 0, 'Rom 14:11', 0, 9, 7, 9, 25, 'high',
     'Isa 45:23 // Rom 14:11 — every knee shall bow, every tongue confess',
     ('Near-verbatim LXX quotation introduced by Paul with "for it is written" (gegraptai gar). '
      'LXX Isa 45:23: emoi kampsei pan gonu kai pasa glossa exomologesetai — '
      'Rom 14:11 matches word-for-word across four rare Greek terms: '
      'kampsei (bow), gonu (knee), glossa (tongue), exomologesetai (confess/acknowledge). '
      'Paul uses this to ground "do not judge your brother": because YHWH alone is God '
      'and every person will give account to him, human judgments of one another are displaced. '
      'Isaiah 45 monotheistic claim (there is no other God) becomes the theological basis '
      'for equality before the divine judgment seat. Grade AAA: verbatim LXX, essential to argument.'),
     'isa45,romans14,every-knee,universal-judgment,explicit-quotation,lxx,monotheism'),

    # 2 — Isa 45:21 // Rom 3:26 — righteous God and savior / just and justifier
    ('Isa 45:21', 0, 'Rom 3:26', 0, 3, 8, 9, 20, 'high',
     'Isa 45:21 // Rom 3:26 — God righteous and savior / just and the justifier',
     ('Isa 45:21 LXX: theos dikaios kai sozon — "God righteous and saving." '
      'The only place in the OT that conjoins these two divine attributes as a single formula: '
      'God is simultaneously righteous (dikaios) AND savior (sozon). '
      'Rom 3:26 resolves exactly this tension: "that he might be just (dikaion) '
      'and the justifier (dikaiounta) of the one who has faith in Jesus." '
      'Paul paradox — God is just AND justifier — fulfils Isa 45:21 rare pairing. '
      'The cross simultaneously satisfies God justice and provides salvation, '
      'exactly as Isa 45:21 holds together. Not an explicit quotation but a deep verbal echo '
      'of the rarest OT conjunction of righteousness and salvation. '
      'This is the theological heart of Romans 3:21-26 and arguably of the entire letter. '
      'Grade AA: rare LXX pairing, essential to the letter argument.'),
     'isa45,romans3,justification,righteousness,dikaios,savior,theological-heart,lxx'),

    # 3 — Isa 45:24-25 // Rom 11:26 — all seed of Israel justified
    ('Isa 45:24', 1, 'Rom 11:26', 0, 2, 8, 7, 17, 'high',
     'Isa 45:24-25 // Rom 11:26 — all the seed of Israel shall be justified',
     ('Isa 45:25 LXX: apo kuriou dikaiothesontai kai en to theo endoxasthesontai '
      'pan to sperma ton huion Israel — '
      '"all the seed of the sons of Israel shall be justified from the Lord and shall glory in God." '
      'Only OT verse applying dikaioo (justify) to "all Israel" as eschatological destiny. '
      'Rom 11:26 declares "all Israel will be saved" — climax of Romans 9-11. '
      'Isa 45:25 is the OT warrant: Israel ultimate justification in YHWH is promised. '
      'Isa 45:24 "only in YHWH, righteousness and strength" also grounds '
      'Paul boasting-in-God theme (Rom 5:2,11). '
      'The dikaio-root running through Isa 45:21,24-25 forms the OT backbone of '
      'Romans justification argument from 1:17 to 11:26. Grade AA.'),
     'isa45,romans11,all-israel,justification,eschatology,seed-of-israel,lxx'),

    # 4 — Isa 45:20-22 // Rom 10:11-13 — universal salvation offer
    ('Isa 45:20', 2, 'Rom 10:11', 2, 1, 7, 4, 12, 'high',
     'Isa 45:20-22 // Rom 10:11-13 — turn to me and be saved, all the ends of the earth',
     ('Isa 45:22 is the most explicit universal salvation invitation in the OT: '
      '"Turn to me and be saved, all the ends of the earth, for I am God and there is no other." '
      'LXX: strephete pros me kai sothesesthe hoi ap eskhatou tes ges. '
      'The logical structure: because YHWH alone is God, salvation is open to all without distinction. '
      'Rom 10:12-13 makes the identical argument: "no distinction between Jew and Greek; '
      'same Lord is Lord of all, bestowing riches on all who call on him; '
      'everyone who calls on the name of the Lord will be saved." '
      'Paul "no distinction" (10:12) is the direct theological consequence of Isa 45:22 monotheism. '
      'Paul quotes Joel 2:32 and Isa 52:7 explicitly, but Isa 45:22 is the underlying OT logic. '
      'Isa 45:21 "declare this" proclamation also parallels the herald chain of 10:14-15. '
      'Grade A-: strong thematic parallel, background rather than explicit quotation.'),
     'isa45,romans10,universal-salvation,no-distinction,gentiles,all-nations,lxx,proclamation'),

    # 5 — Isa 45:1-7 // Rom 1:18-25 — creator sovereignty vs. idolatry
    ('Isa 45:1', 6, 'Rom 1:18', 7, 0, 5, 3, 8, 'moderate',
     'Isa 45:1-7 // Rom 1:18-25 — creator of light and darkness vs. idol worship',
     ('Isa 45:7 asserts radical Creator-sovereignty: '
      '"I form light and create darkness, I make shalom and create calamity — I am YHWH." '
      'Isa 45:5-6 repeats the monotheistic formula ("I am YHWH and there is no other"). '
      'Isa 45:20 mocks idol-carriers "praying to a god that cannot save." '
      'Rom 1:18-25 mirrors this logic: God eternal power and divine nature are visible in creation; '
      'those who suppress this truth exchange God glory for images of creatures, '
      'worshipping the creature rather than the Creator (1:25). '
      'Connection is thematic and mediated — Paul proximate intertext in Rom 1 '
      'is likely Wisdom of Solomon 13-14, which itself draws on Isa 40-48 idol polemic. '
      'Isa 45 creator-monotheism provides the deep OT foundation Paul argument inhabits. '
      'Grade B+: genuine thematic parallel, not primary source.'),
     'isa45,romans1,creator,idolatry,monotheism,natural-theology,wrath'),
]

insert_sql = """INSERT INTO allusions
    (source_ref, source_range, target_ref, target_range,
     terms_score, themes_score, thesis_score, total_score,
     confidence, context, explanation, tags)
    VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?)"""

for r in rows:
    execute(insert_sql, r, database=db)
    print(f"Inserted: {r[0]}(+{r[1]}) -> {r[2]}(+{r[3]})  score={r[7]}  {r[8]}")

print("\n── Isa 45 allusions in DB ──")
all_rows = query('SELECT id, source_ref, source_range, target_ref, target_range, total_score, confidence FROM allusions ORDER BY id', database=db)
for r in all_rows:
    if 'Isa 45' in (r['source_ref'] or '') or 'Isa 45' in (r['target_ref'] or ''):
        print(f"  ID {r['id']:2d}: {r['source_ref']}(+{r['source_range']}) -> {r['target_ref']}(+{r['target_range']})  score={r['total_score']}  {r['confidence']}")
print(f"\nTotal allusions in DB: {len(all_rows)}")
