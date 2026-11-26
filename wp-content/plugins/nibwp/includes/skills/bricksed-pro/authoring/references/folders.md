# Folders and ids

## Type → folder

`type` is one of four values, and each is a top-level folder in the library.

| `type` | Folder | What belongs there |
|---|---|---|
| `layout` | `Layouts` | A section-sized arrangement: a hero, a feature grid, a pricing table |
| `element` | `Elements` | One small piece: a button, a badge, a card face |
| `component` | `Components` | A reusable unit built to be configured through properties |
| `template` | `Templates` | A whole page |

Any other value is refused before it reaches Bricksed.

## Category → sub-folder

`category` names the sub-folder, for example `Hero`. A `/` in it becomes ` - `, so `Grids/Cards` is stored as `Grids - Cards`. Anything unsafe in a path is stripped.

Bricksed matches an existing folder regardless of case, so `grids - cards` and `Grids - Cards` land in the same place on any host.

## The id

`list` and `search` return ids. They look like `{type}s-{category-slug}-{name-slug}`: `layouts-hero-simple-hero`, `components-accordion-tabs-editorial-accordion-rows`. You cannot construct one by guessing — the slug is derived from the name at the moment of saving, and a collision adds a numeric suffix.

The id is fixed when a component is saved and stays with that file. A rename keeps the id, so after renaming, the id may no longer spell the name. A move gives a new id, because a move makes a new file.

## Collisions

Saving a name that already exists in that folder does **not** overwrite it. Bricksed appends `-2`, `-3`, and so on, up to 500, and then answers 409. To change a component, `update` it by id.

## Updating

Bricksed rewrites a component in place. It changes the details — name, brand, tags, description — and the manifest entry, and it never moves the file.

- **Details only.** Leave `block` out. The content stays exactly as it is. Anything you do not send is kept from the stored component.
- **Content too.** Send a `block`, and send all of it: it replaces the stored content. `get` first. It is held to the portability rules, like a save.
- **Never a partial block.** A block needs Bricks elements (`bricks.content`), or a tree or component definitions in the shared format. One without — empty, or only classes — would replace the component with fragments, so it is refused.

## Rename

Rename keeps the id. It is a details-only update with a new `name`: the display name in the tree and the manifest changes; the file and the id do not.

## Move

`update` and `rename` cannot change `type` or `category` — the details would name one folder while the file sat in another. `move` does it properly:

1. it saves a **copy** in the new type or category, which gets a **new id**;
2. then it **deletes** the original.

In that order, so a failure never loses the component. If the copy fails, nothing is deleted. If the delete fails, you get the copy, its new id, `moved: false` and a warning that the original is still in its old folder — delete it once the copy is checked.

Moving a component to where it already is, or with no destination, is refused.

## Duplicate

`duplicate` fetches a component and saves a copy under a new `name`, optionally into a different `type` or `category`. The original is not touched. The copy keeps the tags, brand, description, preview image and demo URL unless you override them. It is a plain save, so it is not held to the portability rules a fresh build would be.

## Per-user folders and pins

`folders` and `set_folders` read and write a per-user folder structure used by Bricksed's own panel. `set_folders` replaces the whole structure with what you send, so read it with `folders` first, change it, and send all of it back; calling it with no `folders` is refused. `pins` lists pinned items. These organise the view, not the files on disk.

## Where it lives

Bricksed keeps the library inside its own plugin folder. A plugin update can replace that folder, taking saved components with it. NibWP cannot change where another plugin stores its data, so every write returns a `warning` saying so. Tell the user each time, and suggest they back up what matters before updating Bricksed.
