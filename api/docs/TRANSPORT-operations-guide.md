# Transport rates and lists: guide for Operations

**Where:** Operations → **Transport**. Three tabs: **Rates** (road transport that prices a quotation), **Flights** and **Trains** (details only, no prices).

## Rates: one list, one line per rate

There is nothing to set up first. No countries, places or providers screens. The first rate can be saved on day one.

**Quick add (top of the list):** choose the type (cab, van, bus, ferry), type the destination, the service name, the price, and how it is charged (per day, per trip, per person), then press **Add**. A rate needs only those four things.

- **Destination** is typed with suggestions: the same names Hotels use. The country and currency come with the destination, so you are never asked for them on every rate. The first time a destination is used on a rate you confirm its country and currency once.
- **+ Add rate** opens the short form. **More details** are all optional: supplier, season name and dates, seats, hours per day, km included, extra hour, note. For buses and ferries add **From**, **To** and one way or return.
- **Save and add another** keeps the type and destination filled in, so a whole sheet can be keyed quickly.
- **Supplier** is just a name typed on a rate. It is remembered and suggested next time, and one supplier typed in different spellings of the same name is kept once.
- **⋯ menu on each line:** Edit, **Duplicate** (for example copy Ertiga to Innova), **Add season**, Delete.
- **Seasons:** a service keeps one line. Each extra season has its own dates and price; the main price covers the rest of the year. A price with no dates applies all year. A season without dates is kept but marked "Dates needed".
- **Change prices:** tick several lines, press **Change prices**, choose increase or decrease by a percentage and **Preview**. The old and new prices are shown, and nothing is saved until you press Apply. Seasons move with their line.
- **Filters:** All, Cabs, Vans, Buses, Ferries, **Needs a price**; search finds a service, place or supplier.
- Rates are shown grouped by destination, with the country and currency on the heading. A rate may use a different currency from its destination if it needs to.

**Import from sheet:** upload the supplier's Excel or CSV (or download the blank template first). Press **Check the file**: every row is **Ready**, **Check** (a choice is needed: how it is charged, or a new destination's country and currency) or **Missing** (for example no price; it will be skipped). Make the choices, then **Import**. Nothing is saved until you import. Importing again updates rows that exist and never duplicates them. Several rows for the same service with different seasons join one line.

**Money and who sees it:** rates are the vendor's price. Only Operations and Admin see the Rates list and the suppliers. Sales never do; they will see selling prices on quotations.

**Not here:** flights and trains have no prices and live in their own tabs. The sample list (the Manali sheet and a Singapore example) is loaded with `php artisan transport:load-rates-sample`.

## Flights and trains (details only, no prices)

**Where:** Transport has three tabs: **Rates** (price a quotation), **Flights** and **Trains** (details shown to customers as suggestions).

- **Add flight / Add train:** airline or train name and number, from and to (with airport or station codes), departs, arrives (tick "arrives the next day" if it does), the days it **runs on**, class, and a note. There is **no price field**.
- **Also reaches:** if a service ends near, not at, a destination (Kalka for Manali, Chandigarh for Manali), write the destination(s) here and put how to continue in the **note** ("Then a road transfer to Manali"). Quotations for that destination then suggest it.
- **Edit, Duplicate, Delete** are under the ⋯ menu on each row. Search, and filter by route.
- **Import from Excel:** download the template, fill one row per service, **Check first**, then Import. Importing again updates rows that already exist.
- **Needs update:** a service not touched for 180 days is flagged. Open it and save to confirm it is still correct. Agree who reviews the lists and how often.
- Sample schedules (marked "Sample schedule: confirm before use") can be loaded with `php artisan transport:load-schedules-sample`. Replace them with the real list before use.
