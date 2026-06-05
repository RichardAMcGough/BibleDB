# Notes + Gematria Search QA Checklist

This checklist validates note visibility, admin controls, and gematria note search behavior.

## Preconditions

- Notes enabled in web config.
- Two local demo users configured (admin and non-admin) or real phpBB users.
- At least one verse with gematria note values available for testing.

## Demo User Setup (local dev)

Use separate browser sessions:

1. Normal window: admin user.
2. Incognito window: non-admin user.

Switch with URL once per session:

- `...&demo_user=admin`
- `...&demo_user=viewer`

## Visibility Rules to Verify

1. Guest sees only public notes.
2. Non-admin sees public notes + own private notes.
3. Non-admin does not see other users' private notes.
4. Admin sees all notes.

## Note CRUD + Visibility

1. As non-admin user, create a note and set gem `Std` and `Ord`.
2. Confirm note saves as private (non-admin cannot publish).
3. As admin, open same verse and confirm lock/private marker appears.
4. As admin, use `Make Public` action for non-admin note.
5. Confirm non-admin and guest now see the note.
6. As admin, use `Make Private` and verify it hides from guest/non-owner.

## Gematria Search Behavior

Use `search.php?mode=gematria&standard=<value>` where `<value>` matches test notes.

1. As guest: only public matching notes appear.
2. As non-admin owner: own private + public matching notes appear.
3. As different non-admin: other users' private notes do not appear.
4. As admin: all matching notes appear.
5. Matching is by `gem_std` OR `gem_ord`.

## Security/Transport Checks

1. Local (no remote API): note writes require CSRF token.
2. Remote API mode: proxied note writes require valid HMAC signature.
3. Invalid/unsigned proxy write request is rejected with `invalid proxy signature`.

## Regression Spot Checks

1. Note edit/delete still works for owner.
2. Admin can delete any note.
3. Verse note count badge updates after create/update/delete/visibility toggle.
4. Gematria word-form search results remain unchanged.

## Optional SQL Sanity Queries

Adjust IDs/values for your environment.

```sql
-- Check notes and visibility
SELECT id, user_id, username, is_public, gem_std, gem_ord, title
FROM verse_notes
ORDER BY id DESC
LIMIT 20;

-- Find notes that should match a gematria search value
SELECT id, user_id, is_public, gem_std, gem_ord, title
FROM verse_notes
WHERE gem_std = 913 OR gem_ord = 913
ORDER BY id;
```
