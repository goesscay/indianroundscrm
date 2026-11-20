# Transport master: guide for Operations

**Where:** Operations → **Transport**.

**Set up once**
1. **Countries and places** (button on the Transport page): add a country with its currency (for example Singapore, SGD), then cities and pickup points (airport, station, bus stand, harbour).
2. **Add transport:** a provider (cab, van, bus or ferry company) with its country, destination and currency. Switch it off to stop offering it; do not delete it if old quotations use it.
3. On the provider page add **vehicles** (with seats; seats decide how many vehicles a group needs), **routes** (sightseeing, transfer, intercity, with "what the customer is told is included") and **rate cards**.

**Rate cards**
- Each rate is a vehicle, optionally a route, how it is charged (per vehicle per day, per transfer, per route package, or per person), a season name, **start and end dates**, and the vendor cost in the provider's currency.
- Rates with no dates are kept but marked **dates needed** and are **not used for pricing** until the dates are filled in.
- Two rates of the same vehicle, route and charge may not overlap in dates.

**Import a vendor sheet:** Transport → Import rates → download the template, fill one row per provider, vehicle, route and season, **Check first**, then Import. Importing again updates rates; it never duplicates them.

**Exchange rates:** the default rates new quotations start with are kept at `/api/transport/exchange-rates` (₹ per 1 unit of the currency). Each quotation keeps its own copy.

**Margin:** a provider's own margin if set, otherwise the destination margin, otherwise the default (Quotation Content → margins). Sales never see costs or margin.

**Before a quotation goes out:** open it, check there is no "Rate needed" warning, and read the **Your transport** section of the PDF.
