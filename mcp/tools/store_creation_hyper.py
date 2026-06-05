"""
Persist the Creation HyperHolograph insights (Genesis 1:1-5 <-> John 1:1-5)
into biblewheel_research. Idempotent: clears prior rows tagged
'creation-hyperholograph' before re-inserting.

Source chain: biblewheel.com/GR/GR_Creation.php ... GR_Creation_Hyper.php
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from shared.db import execute, execute_many, query

TAG = "creation-hyperholograph"
SRC = "https://www.biblewheel.com/GR/GR_Creation_Hyper.php"

# (verse_ref, value, method, observation, tags)
INSIGHTS = [
    # I. The Seed
    ("Gen 1:1", 2701, "hebrew-standard",
     "Genesis 1:1 = 2701 = 37 x 73 = T(73), the 73rd triangular number. "
     "7 words, 28 letters = T(7). 37 = ordinal value and 73 = standard value of "
     "chokmah (Wisdom): creation is crystallized Wisdom (Prov 3:19).",
     f"{TAG},seed,triangular,wisdom,37,73"),
    ("Gen 1:1", 703, "hebrew-standard",
     "Last two words v'eth ha'aretz ('and the earth') = 703 = T(37) = 19 x 37. "
     "The part (T(37)) is embedded in the whole (T(73)): holographic self-similarity.",
     f"{TAG},seed,triangular,37"),

    # II. The Frame
    ("Gen 1:1", 1209, "hebrew-standard",
     "Frame = first word (bereshit 913) + last word (ha'aretz 296) = 1209 = 3 x 13 x 31. "
     "13 = echad (ONE), 31 = El (GOD) and its mirror-reversal: Trinity x ONE x EL brackets the verse.",
     f"{TAG},frame,13,31,unity"),
    ("Gen 1:1", 481, "hebrew-standard",
     "Inner words 3+5: Elohim (86, God) + HaShamayim (395, the Heaven) = 481 = 13 x 37. "
     "481 also = Greek 'he Genesis' (THE GENESIS, the book title) and Hebrew Kol El Shaddai "
     "(the Voice of God Almighty). One number = God+Heaven, the book's title, the Almighty's Voice.",
     f"{TAG},frame,481,13,37,genesis"),

    # III. The Weave (alternating words) + Matt 5:18
    ("Gen 1:1", 1690, "hebrew-standard",
     "Odd words (1,3,5,7) sum = 1690 = 10 x 13^2 = 10 x ONE-squared. The decade (iota=10) "
     "times Unity squared.",
     f"{TAG},alternating,odd,13,unity"),
    ("Gen 1:1", 1011, "hebrew-standard",
     "Even words (2,4,6) sum = 1011 = 3 x 337 = 3 x S(8) (8th Star number). "
     "Equals Greek 'ho ouranos kai he ge' (the heaven and the earth, Matt 5:18) = 1011. "
     "The even words of the Hebrew creation verse = the phrase Jesus says shall not pass.",
     f"{TAG},alternating,even,star,matt5-18,1011"),
    ("Matt 5:18", 1011, "greek-isopsephy",
     "'ho ouranos kai he ge' (the heaven and the earth) = 70+891+31+8+11 = 1011 = 3 x S(8). "
     "Matt 5:18: not one iota (i=10) shall pass till heaven and earth pass. The iota that "
     "completes John 1:1-5 (4 subscripts = 40) is the same smallest letter Jesus magnifies.",
     f"{TAG},matt5-18,iota,star,1011"),

    # IV. First Day internal structure
    ("Gen 1:2", 1369, "hebrew-standard",
     "'Spirit of God moved upon the waters' (G4 of the First Day) = 1369 = 37^2. "
     "The Spirit's motion = the square of the Wisdom-prime.",
     f"{TAG},first-day,37,spirit"),
    ("Gen 1:3", 1776, "hebrew-standard",
     "First Day light-pairs: 'Let there be light'(813) + 'God saw the light was good'(963) "
     "= 1776 = 48 x 37; and (963 + 813 'divided light from darkness') = 1776 likewise. "
     "Light spoken = light divided (both 813), flanking the sight of goodness.",
     f"{TAG},first-day,1776,37,48,light"),

    # V. The Word made numerical
    ("John 1:1", 3627, "greek-isopsephy",
     "John 1:1 = 3627 (with the iota subscript of arche, 719). 3627 = 3 x 1209 = "
     "3 x (Genesis frame) = 39 x 93 = 9 x 13 x 31. 39 = YHVH Echad (ONE LORD, the Shema), "
     "93 = agape (LOVE) = thelema (WILL). The Word's first verse = the Genesis frame tripled "
     "= ONE LORD x LOVE = Trinity-squared x ONE x EL.",
     f"{TAG},john,3627,39,93,shema,love"),

    # VI. The Name equation (John 1:2-5)
    ("John 1:2-5", 23088, "greek-isopsephy",
     "John 1:2-5 = 23088, the Name-equation, factoring five ways: "
     "26 x 888 (YHVH x JESUS); 39 x 592 (ONE LORD x Theotes/GODHEAD); "
     "13 x 1776 (ONE x First-Day holograph); 48 x 481 (48 x THE GENESIS); "
     "111 x 208 (Aleph/WONDERFUL x 'He created it'). Since 888=24x37 and 26=2x13, "
     "26x888 = 48x13x37 = 48 x 'he Genesis' -- the Father's Name times the Son's Name "
     "equals the Genesis of all they made.",
     f"{TAG},john,23088,yhvh,jesus,godhead,genesis"),

    # VII. The Seal (whole prologue)
    ("John 1:1-5", 26715, "greek-isopsephy",
     "The Divine Prologue John 1:1-5 = 26715 = 13 x 2055 = 5 x 39 x 137. "
     "2055 = panta di autou egeneto ('all things were made through him', v.3a) = 15 x 137. "
     "137 = 27+37+73 (sum of the Generating Set) = Elohi Amen (The God of Truth) = inverse "
     "fine-structure constant. The five verses = ONE x 'all things made through him' "
     "= Verses x ONE LORD x God-of-Truth. Requires all four iota subscripts (=40) to resolve.",
     f"{TAG},john,26715,seal,2055,137,iota"),
    ("John 1:3", 2055, "greek-isopsephy",
     "'panta di autou egeneto' (all things were made through him) = 2055 = 15 x 137 = 3 x 5 x 137. "
     "The running sum of the first four words of v.3; the self-reflective key: 26715 = 13 x 2055.",
     f"{TAG},john,2055,137,creation"),
    ("John 1:2", 2876, "greek-isopsephy",
     "John 1:2 = 2876 = 4 x 719 (719 = arche in the dative, with iota subscript). "
     "Fragment 'the same was in the beginning' = 1872 = 39 x 48 (ONE LORD x Majesty/Star).",
     f"{TAG},john,2876,719,1872"),
]

# number_facts: standalone number meanings
NUMBER_FACTS = [
    (2701, "Genesis 1:1 (Hebrew) = 37 x 73 = T(73). 7 words, 28 letters = T(7). 100x27+1.", SRC),
    (1209, "Genesis 1:1 frame (first+last word) = 3 x 13 x 31; John 1:1 = 3 x 1209.", SRC),
    (481, "he Genesis (THE GENESIS, Greek) = 13 x 37; also Elohim+HaShamayim and Kol El Shaddai.", SRC),
    (3627, "John 1:1 = 39 x 93 = 9 x 13 x 31 = 3 x 1209 (ONE LORD x LOVE).", SRC),
    (23088, "John 1:2-5 = 26x888 = 39x592 = 13x1776 = 48x481 = 111x208.", SRC),
    (26715, "John 1:1-5 = 13 x 2055 = 5 x 39 x 137 (ONE x 'all things made through him').", SRC),
    (2055, "'all things were made through him' (John 1:3a) = 15 x 137 = 3 x 5 x 137.", SRC),
    (137, "Sum of Generating Set 27+37+73; Elohi Amen (God of Truth); inverse fine-structure constant.", SRC),
    (373, "Logos (the Word) = S(8)+T(8) = 337+36; prime reversal of 337 = S(8).", SRC),
    (1011, "Even words of Gen 1:1 = 3 x S(8); = 'the heaven and the earth' (Matt 5:18).", SRC),
    (888, "Jesus (Iesous) = 24 x 37 (Greek alphabet count x Wisdom-prime).", SRC),
    (1776, "First Day holograph value = 48 x 37; light-pairs of Gen 1:3-4.", SRC),
]

def main():
    # idempotent clear
    deleted = execute("DELETE FROM insights WHERE tags LIKE %s", (f"%{TAG}%",))
    execute("DELETE FROM number_facts WHERE source_url = %s", (SRC,))

    n1 = execute_many(
        "INSERT INTO insights (verse_ref, value, method, observation, tags) "
        "VALUES (?, ?, ?, ?, ?)", INSIGHTS)
    n2 = execute_many(
        "INSERT INTO number_facts (number, description, source_url) "
        "VALUES (?, ?, ?)", NUMBER_FACTS)

    print(f"Inserted {n1} insights, {n2} number_facts.")
    print("Verify (insights):")
    for r in query("SELECT id, verse_ref, value, LEFT(observation,48) obs "
                   "FROM insights WHERE tags LIKE %s ORDER BY id",
                   (f"%{TAG}%",), database="biblewheel_research"):
        print(f"  #{r['id']:>3}  {r['verse_ref']:<10} {r['value']:>6}  {r['obs']}...")

if __name__ == "__main__":
    main()
