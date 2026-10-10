# Payment gateway marks

`Dicex_Connect_Credit::provider_logo()` looks for a file here named after the gateway,
lowercased, with every non-alphanumeric character removed. `.svg` is preferred
and is looked for first; `.png` is the fallback.

| Gateway (`paymentProvider`) | File shipped |
| --- | --- |
| `Saman`   | `saman.svg` — the SEP (Saman Electronic Payment) mark |
| `Sepehr`  | `sepehr.svg` |
| `Digipay` | `digipay.svg` |
| `Thawani` | `thawani.svg` |
| `Tap`     | `tap.png` — Tap's app icon, supplied by the account owner (a Tap merchant) on 2026-10-10 |

Paymob was removed in 1.9.0 with its mark: DiceX did not sign with Paymob and
signed with Tap instead.

`tap.png` is cut out of the image the owner supplied, which sat the dark icon on
a white square: the white surround was made transparent by a flood fill from the
four corners — so the white lettering inside the icon is never touched — and the
grey rim it left became the icon's own dark colour at partial opacity, which
keeps the rounded corners smooth on any background. 160px square, four times the
tile's height. Tap publishes no SVG; the full-size cut-out and the supplied
original are in the landing's `design/source-images/`.

The tile gives each mark a 40px-tall slot and up to 100px of width, so the three
different shapes — a wide wordmark, a near-square ribbon and a square icon — all
sit on the same line with their names underneath. A gateway with no file here
falls back to a plain tile carrying its name, which is why the chooser was
complete before these arrived and why a fourth gateway needs no code change.

Only add marks you have the right to redistribute: everything in a plugin on
WordPress.org has to be GPL-compatible, licence and assets alike.
