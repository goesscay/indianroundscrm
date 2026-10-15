<?php

namespace App\Support;

/**
 * Example call scripts the team starts from. They are only a starting point: the admin edits the team defaults and every
 * salesperson can keep a version of their own. Placeholders: {customer} {destination} {me} {company}.
 * Keep the claims general (places, kinds of stay, what to ask); prices, visa rules and availability are for the quote.
 */
class CallScriptDefaults
{
    private const ASK = <<<'TXT'
FIND OUT
- When do you plan to travel, and for how many nights?
- Who is travelling? Adults, children (with ages), any senior citizens?
- Which city will you start from?
- What kind of trip is it: honeymoon, family, friends, or a special occasion?
- Hotel preference: 3-star, 4-star, 5-star, or a villa / resort?
- Is there a budget in mind, per person or for the whole trip?
- Does everyone have a valid passport? (international trips)
TXT;

    private const CLOSE = <<<'TXT'
NEXT STEP
"Thank you, {customer}. I have noted everything. I will check availability and send you a detailed plan with prices on WhatsApp. Is this the best number, and what time suits you for a call back?"

BEFORE YOU HANG UP
- Confirm the WhatsApp number and the best time to call.
- Say the next step and when they will hear from you.
- Thank them by name.
TXT;

    /** @return list<array{destination:?string,title:string,body:string}> */
    public static function all(): array
    {
        $open = "OPENING\n\"Hello {customer}, this is {me} from {company}. You enquired about a trip to {destination}. Do you have two minutes to talk about it?\"\n\n";

        return [
            ['destination' => null, 'title' => 'General enquiry call', 'body' => $open.self::ASK."\n\nEXTRA QUESTIONS\n- Any must-do experiences, or things you want to avoid?\n- Have you travelled to {destination} before?\n\n".self::CLOSE],
            ['destination' => 'Bali', 'title' => 'Bali call', 'body' => $open."PITCH IN ONE BREATH\n\"Bali suits almost every kind of holiday: Ubud for culture and rice terraces, Seminyak and Uluwatu for beaches and sunsets, and Nusa Penida for the cliffs and islands.\"\n\n".self::ASK."\n\nBALI EXTRAS\n- Do they want a quiet stay (Ubud), a beach-and-nightlife stay (Seminyak), or both?\n- Private pool villa or a regular hotel room?\n- Honeymoon? Offer a candlelight dinner and a private-driver day tour.\n- Interested in Nusa Penida or Gili island day trips? Water activities?\n- Airport pick-up and a private car for the days out, or only transfers?\n\n".self::CLOSE],
            ['destination' => 'Maldives', 'title' => 'Maldives call', 'body' => $open."PITCH IN ONE BREATH\n\"In the Maldives each resort is on its own island, so the resort you choose decides the whole holiday: the villa, the meals, the activities and how you get there.\"\n\n".self::ASK."\n\nMALDIVES EXTRAS\n- Beach villa or overwater villa?\n- Meal plan: half board, full board or all-inclusive? (the biggest cost difference)\n- Transfers are by speedboat or seaplane depending on the resort. Explain it affects timing and cost.\n- Honeymoon or anniversary? Mention celebration set-ups the resort can arrange.\n- Interested in snorkelling, diving, sunset cruises or a spa?\n- Check their dates early: popular resorts fill up first.\n\n".self::CLOSE],
            ['destination' => 'Singapore', 'title' => 'Singapore call', 'body' => $open."PITCH IN ONE BREATH\n\"Singapore is easy and safe, with something for every age: Sentosa and Universal Studios, Gardens by the Bay, the Night Safari and great food.\"\n\n".self::ASK."\n\nSINGAPORE EXTRAS\n- Travelling with children? Ask their ages (theme park, Sentosa, zoo, Night Safari).\n- Do they want to add Malaysia or Bintan / Batam to the trip?\n- Hotel location: city centre or near Sentosa?\n- Want a city pass, theme-park tickets or only the hotel and transfers?\n\n".self::CLOSE],
            ['destination' => 'Thailand', 'title' => 'Thailand call', 'body' => $open."PITCH IN ONE BREATH\n\"Thailand gives you beaches and city in one trip: Phuket and Krabi for the islands, Bangkok and Pattaya for shopping, shows and nightlife.\"\n\n".self::ASK."\n\nTHAILAND EXTRAS\n- Which parts: Phuket, Krabi, Bangkok, Pattaya, or a mix?\n- Island day trips such as Phi Phi or James Bond Island?\n- Shows, shopping and nightlife, or quiet beaches?\n- Honeymoon? Offer a private speedboat or a romantic dinner.\n- Domestic flights between cities needed?\n\n".self::CLOSE],
            ['destination' => 'Dubai', 'title' => 'Dubai call', 'body' => $open."PITCH IN ONE BREATH\n\"Dubai is a short flight and a big holiday: the Burj Khalifa, desert safari, the Marina and malls, and a day trip to Abu Dhabi if you like.\"\n\n".self::ASK."\n\nDUBAI EXTRAS\n- Check passport validity and tell them our team will guide them on the visa.\n- First time? Suggest city tour, desert safari, Burj Khalifa and a dhow cruise.\n- Add Abu Dhabi (Grand Mosque, Ferrari World)?\n- Theme parks or shopping festivals on their dates?\n- Hotel area: Deira (budget-friendly), Downtown (views) or the Marina (beach side)?\n\n".self::CLOSE],
            ['destination' => 'Vietnam', 'title' => 'Vietnam call', 'body' => $open."PITCH IN ONE BREATH\n\"Vietnam is a lovely mix of culture and coast: Hanoi and Ha Long Bay in the north, Da Nang, Hoi An and the Golden Bridge in the centre, and Phu Quoc for beaches.\"\n\n".self::ASK."\n\nVIETNAM EXTRAS\n- North, centre, south, or a route across them? (this decides the internal flights)\n- Ha Long Bay: day cruise or an overnight cruise?\n- Beach time at Phu Quoc, or sightseeing only?\n- Check passport validity and tell them our team will guide them on the visa.\n- Food-loving? Mention street-food and cooking-class options.\n\n".self::CLOSE],
            ['destination' => 'Kerala', 'title' => 'Kerala call', 'body' => $open."PITCH IN ONE BREATH\n\"Kerala is hills, backwaters and beaches in one trip: Munnar and Thekkady for tea and spice country, Alleppey for the houseboat, Kovalam or Varkala for the beach.\"\n\n".self::ASK."\n\nKERALA EXTRAS\n- Which parts: Munnar, Thekkady, Alleppey, Kochi, Kovalam / Varkala?\n- Houseboat: a night on board, or only a day cruise?\n- Ayurveda or spa experiences?\n- Starting city: Kochi or Trivandrum airport / rail?\n- Travelling in the rains? Mention the monsoon packages and the greener hills.\n\n".self::CLOSE],
            ['destination' => 'Kashmir', 'title' => 'Kashmir call', 'body' => $open."PITCH IN ONE BREATH\n\"Kashmir is Srinagar's Dal Lake and houseboats, Gulmarg's gondola and meadows, Pahalgam's valleys and Sonamarg's glaciers.\"\n\n".self::ASK."\n\nKASHMIR EXTRAS\n- Season: green meadows in summer, or snow in winter?\n- Houseboat night on Dal Lake?\n- Gulmarg gondola, Pahalgam and Sonamarg: which are must-haves?\n- Flying into Srinagar? Add Jammu / Katra or Ladakh?\n- Elders in the group? Mention the altitude and the pace of the plan.\n\n".self::CLOSE],
            ['destination' => 'Goa', 'title' => 'Goa call', 'body' => $open."PITCH IN ONE BREATH\n\"Goa has two moods: North Goa for beaches, parties and water sports, South Goa for quiet beaches and resorts.\"\n\n".self::ASK."\n\nGOA EXTRAS\n- North Goa (lively) or South Goa (quiet)?\n- Beach-front resort or a budget stay near the beach?\n- Water sports, casino, nightlife, or just rest?\n- Sightseeing: old churches, forts, spice plantation, dolphin trip?\n- Self-drive scooter or a car with a driver?\n\n".self::CLOSE],
        ];
    }
}
