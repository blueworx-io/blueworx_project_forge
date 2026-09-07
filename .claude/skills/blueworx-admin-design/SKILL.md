---
name: blueworx-admin-design
description: Use this skill to generate well-branded interfaces and assets for BlueWorx WordPress plugin admin screens, either for production or throwaway prototypes/mocks/etc. Contains essential design guidelines, colors, type, fonts, assets, and UI kit components for prototyping.
user-invocable: true
---

Read the `readme.md` file within this skill, and explore the other available files.

All CSS lives in one self-contained file, `styles.css`, at the root of this skill. Copy it
verbatim — no edits, no minifying — to a plugin's `assets/blueworx-admin-design.css`, and copy
`fonts/` next to it. Never hand-write brand CSS that duplicates what is already in there.
If creating visual artifacts (slides, mocks, throwaway prototypes, etc), copy assets out and create static HTML files for the user to view. If working on production code, you can copy assets and read the rules here to become an expert in designing with this brand.
If the user invokes this skill without any other guidance, ask them what they want to build or design, ask some questions, and act as an expert designer who outputs HTML artifacts _or_ production code, depending on the need.
