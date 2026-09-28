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
| `Paymob`  | `paymob.svg` |

The tile gives each mark a 40px-tall slot and up to 100px of width, so the three
different shapes — a wide wordmark, a near-square ribbon and a square icon — all
sit on the same line with their names underneath. A gateway with no file here
falls back to a plain tile carrying its name, which is why the chooser was
complete before these arrived and why a fourth gateway needs no code change.

Only add marks you have the right to redistribute: everything in a plugin on
WordPress.org has to be GPL-compatible, licence and assets alike.
