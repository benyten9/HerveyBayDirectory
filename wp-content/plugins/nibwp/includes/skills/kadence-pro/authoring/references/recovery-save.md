# Recovery-save: canonicalise Kadence markup headlessly

## What it fixes

- "Attempt Block Recovery" prompt in the editor (stored HTML ≠ block's canonical `save()`)
- Icon SVGs, iconlist `<li>`, image markup that only the editor generates
- Invalid blocks after programmatic authoring

Run it **after** authoring/mutating a page. Because all design is in attributes, the save preserves it.

## 1. Mint cookies with a REAL session token

Without a real session token `wp_validate_auth_cookie()` passes but wp-admin still bounces to `wp-login.php?reauth=1`.

```php
$uid = 4; $exp = time() + 7200;
$token = WP_Session_Tokens::get_instance( $uid )->create( $exp );
$lg  = wp_generate_auth_cookie( $uid, $exp, 'logged_in',   $token );
$sec = wp_generate_auth_cookie( $uid, $exp, 'secure_auth', $token );
// also need: COOKIEHASH  (LOGGED_IN_COOKIE / SECURE_AUTH_COOKIE names)
```

## 2. Inject via Playwright

`browser_run_code_unsafe` code **must be an `async (page) => {…}` arrow** (statements at top level fail to parse). Clear first — stale/duplicate-path cookies cause reauth.

```js
async (page) => {
  const ctx = page.context();
  await ctx.clearCookies();
  await ctx.addCookies([
    { name:'wordpress_logged_in_<HASH>', value:'<lg>',  domain:'site.tld', path:'/', httpOnly:true, secure:true },
    { name:'wordpress_sec_<HASH>',       value:'<sec>', domain:'site.tld', path:'/', httpOnly:true, secure:true }
  ]);
  return 'ok';
}
```

## 3. Rebuild + save in the editor

If you changed `post_content` via PHP after the editor loaded, **reload first** — its state is stale.

```js
async (page) => {
  return await page.evaluate(async () => {
    const be = wp.data.select('core/block-editor');
    for (let i=0;i<60 && be.getBlocks().length<1;i++) await new Promise(r=>setTimeout(r,500));
    const countInvalid = bs => bs.reduce((n,b)=> n + (b.isValid===false?1:0) + countInvalid(b.innerBlocks||[]), 0);
    const before = countInvalid(be.getBlocks());
    const rebuild = b => wp.blocks.createBlock(b.name, {...b.attributes}, (b.innerBlocks||[]).map(rebuild));
    wp.data.dispatch('core/block-editor').resetBlocks(be.getBlocks().map(rebuild));
    await new Promise(r=>setTimeout(r,1000));
    await wp.data.dispatch('core/editor').savePost();
    await new Promise(r=>setTimeout(r,3000));
    return { before, after: countInvalid(wp.data.select('core/block-editor').getBlocks()) };
  });
}
```

Expect `after: 0`.

## Round-trip hazards

- **`--` → `u002d`**: the editor escapes hyphens in classNames/content. Avoid `--` in authored BEM classes, or repair after: `str_replace(['u002du002d','u002d'], ['--','-'], $content)`.
- **Hand-written overlay divs**: a `<div class="kt-row-layout-overlay …">` placed in innerHTML gets its class **read as the block's `className`** on save — destroying your BEM class. Use overlay **attributes** instead.
- **`kadence/image`**: its save can return null → blanks out. Use `core/image` where a static save must survive.
- A concurrent editor session shows "post is already being edited" — harmless for reading, but don't take over blindly.
