# Organise the Etchedy Library

Using **NibWP** and the **Etchedy Pro** skill, rename, move, duplicate and delete components in the Etchedy library, and keep the folder structure coherent for the client.

## When to use
- "Rename that component to Hero Wide."
- "Move the pricing blocks into a Pricing folder."
- "Make a copy of this hero for the Landing folder."
- "Clean up the library — delete what we no longer use."

## Principles
- **Read the tree before changing it.** Ids come from the manifest and cannot be guessed.
- **Rename keeps the id; move gives a new id.** Etchedy rewrites a component in place, so a rename changes only its name. A move is a copy in the new folder followed by deleting the original — use the new id it returns.
- **Details and content are separate.** A rename or a details update never touches the content. Content changes only when a whole block is sent, and an empty block is refused.
- **Deleting is permanent.** Name the component and its folder, and wait for a yes.
- **A copy never touches the original.**

## Process
1. **Read the tree.** `nibwp/etchedy-manage` with `action: list`, or `search`. Note the existing folders; prefer them to new ones.
2. **Rename.** `action: rename` with the `id` and the new `name`. Rename keeps the id; everything else is kept from the stored component.
3. **Update details.** `action: update` with the `id` and only what changes — tags, description, brand. Leave `block` out and the content is untouched. To change content, `get` first and send the whole `block`.
4. **Move.** `action: move` with the `id` and a new `type` and/or `category`. It saves a copy there (new id), then deletes the original. If it reports `moved: false`, the copy exists but the original could not be removed — check the copy, then delete the original by its old id.
5. **Duplicate.** `action: duplicate` with the `id` and a new `name`, and optionally a new `type` or `category`. It keeps tags, brand, description and preview image unless overridden, and never writes to the original. A name already in the folder gets `-2`, `-3` … rather than overwriting.
6. **Delete.** Confirm with the user by name and folder, then `action: delete`. There is no undo.
7. **Re-read.** `list` again and confirm the tree matches what was asked for.

## Rules
**Do**
- Use `move` to change a type or category; `update` and `rename` refuse to.
- Use the new id after a move; keep using the same id after a rename.
- Ask before every delete, naming the component and its folder.
- Pass on the storage warning that writes return.

**Don't**
- Send an empty `block` — it is refused, because it would wipe the component.
- Send a partial `block` on update: a block replaces all of the content.
- Delete in bulk without listing exactly what will go and getting a yes.
- Expect `save` with an existing name to replace it — it adds a numbered copy. Change a component with `update`.
- Write to the Etchedy data folder directly.

## Validation
- The tree after the change matches the request, read back with `list`.
- Every move reported `moved: true`, or the leftover original was dealt with.
- No component's content changed except where a whole block was deliberately sent.
- Every deletion was named to and confirmed by the user.

## Report
**Renamed:** old → new (id unchanged). **Moved:** `<name>` → `<type / category>` (new id). **Duplicated:** `<name>` → `<copy>`. **Deleted:** list, each confirmed. **Storage:** the warning, passed on.
