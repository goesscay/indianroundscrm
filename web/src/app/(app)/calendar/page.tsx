import { redirect } from "next/navigation";

/** The calendar now lives on the Activities page (List / Calendar toggle, with a Trips layer). Old links and bookmarks land there. */
export default function CalendarRedirect() {
  redirect("/activities?view=calendar");
}
