# Branches, commits and releases

This is the part that makes Builderius different, and the part that costs you if
you ignore it.

## The graph

```
builderius_template            the template
  └─ builderius_branch         post_parent = template
       └─ builderius_commit    post_parent = branch, config in post_content
builderius_release             a published snapshot, with one frozen copy per template
```

- A **commit** holds a config. Commits are not edited; a change is a new commit.
- The **branch head** is the newest commit on the branch. Builderius resolves the
  active commit that way, so a new commit is immediately what the editor opens.
  There is no draft commit to hide an attempt in.
- A **release** is what a visitor sees. It is a frozen copy of a commit, stored as
  a separate record, and **only one release is published at a time**.

## Published means published

A release counts as published when its status is `publish` **or** `future` — a
scheduled release is already being served. Publishing a release unpublishes the
one before it.

## What the skill refuses

`version_mode: overwrite` on a template that the published release serves is
refused, and the refusal names the release version, its id, and the commit it
froze. The reason it is a refusal and not a warning: rolling a release forward
replaces its frozen copy **in place and keeps the version number**, so afterwards
nothing on the site records that the published 1.0 is not the 1.0 anybody
reviewed.

The safe modes are never refused:

- `new_version` — a new commit on the branch. The release is untouched.
- `new_branch` — a branch of its own, then a commit there.

## Reading the state

```
nibwp/builderius-pro-inspect { action: "releases" }
nibwp/builderius-pro-inspect { action: "releases", template_id: 12 }
```

It reports the published release, whether that release serves this template, the
branch head, the commit the release froze, and whether the two differ.

**Unreleased** means the branch head is not the commit the release serves — there
is committed work the site is not showing. That is almost always the answer to "I
changed it and nothing happened".

## Publishing

Publishing a release is the one action that changes what visitors see, and this
skill does not do it. Commit, say what you committed, and let the user publish
when they have looked at it.
