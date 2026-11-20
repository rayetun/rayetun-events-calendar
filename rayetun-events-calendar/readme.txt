=== RayEtun Accessible Events Calendar ===
Contributors:      rayetun
Donate link:       https://wise.com/pay/me/mdrayhanu2
Tags:              events, event calendar, recurring events, accessibility, calendar
Requires at least: 6.9
Tested up to:      7.1
Requires PHP:      8.1
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Accessible event calendar with recurring events, month, week, day and list views, venues, iCal import and export, blocks and shortcodes.

== Description ==

📅 **RayEtun Accessible Events Calendar** is a free **event calendar and event management plugin** for WordPress. Create one-off, multi-day and **recurring events**; show them in **month, week, day and list views**; keep **venues, organisers, speakers and programmes** on file; **import** from iCalendar feeds, `.ics` files and CSV spreadsheets; and let visitors **subscribe** in Google Calendar, Outlook or Apple Calendar.

Accessibility is part of what ships, not a setting to find. The month grid is a real HTML table with arrow-key navigation, every control works before a line of JavaScript runs, and colour never carries meaning on its own. The **Accessibility Guard** then checks your own event content against **21 rules** before you publish, and tells you what to do about each one.

Scheduling is typed, not clicked: write *"Yoga every Tue and Thu at 6pm until June"*, read it back as a plain sentence, check the next ten dates, and publish.

= ✨ Highlights =

* 📅 **Month, week, day and list views** — responsive, keyboard-navigable, no page reload
* 🔁 **Recurring events** — daily, weekly, monthly (by date or by "second Tuesday") and yearly, with intervals, excluded dates and public holidays. A weekly class running two years is **one event**, not 104 posts
* 🕐 **Time zones** — a 6pm class stays at 6pm when the clocks change
* ♿ **Accessibility Guard** — 21 checks on every event, each with a plain-English fix
* 🏛️ **Venues, organisers, speakers and programmes** — with rooms inside venues and real access details
* 🧩 **Four blocks and four shortcodes**, four block patterns, and block bindings
* 📥 **Import** — iCalendar feeds, `.ics` files, CSV spreadsheets, and a migration from The Events Calendar
* 📤 **Export and subscribe** — iCalendar feeds, Google Calendar, Outlook, Apple Calendar, per-event `.ics`
* 🔍 **SEO** — `Event` JSON-LD structured data, an events sitemap, Open Graph and Twitter tags
* 🗺️ **Privacy-friendly venue maps**, or written directions and no map
* 🖥️ **A dashboard built for events** — a drag-and-drop planner and an events table with bulk actions
* 🎨 **Colour themes** — follow your theme, light, dark, each visitor's own setting, or high contrast
* 👩‍💻 **REST API, hooks and filters**, and no locked code

= ♿ The Accessibility Guard: 21 checks before you publish =

An event can look finished and still shut people out: the details live only inside the poster image, the venue has no access information, the link says "click here". The Guard looks for those while you write — **21 checks** beside the Publish button, each with a line saying **what to do about it**:

* 🖼️ **Pictures** — missing alt text, alt text that is really a file name, alt text repeating the event name, alt text as long as a paragraph
* 📄 **Detail trapped in a poster** — a picture and barely any words beside it
* 🔗 **Links** — "click here", a bare web address as link text, the same words going to two places, a new tab with no warning
* 📝 **Structure** — a second level-one heading, a skipped heading level, a table with no header row, no description at all
* 🚪 **Access** — an in-person event with no venue, a venue with no access details, an online event with no joining link
* ⏰ **No finishing time** — anyone booking transport, a carer or an interpreter needs to know how long to book for
* 🎨 **Category colours** too pale, checked against the WCAG contrast ratio
* 🔠 **A name in capitals**, which some screen readers spell out letter by letter, or a row of emoji each read out by name

Three levels, so it never cries wolf: an **error** fails a WCAG success criterion, a **warning** is a likely barrier, a **note** is worth a look. Nothing stops you publishing, and a site-wide score sits on the dashboard.

= 🔁 Recurring events, written the way you say them =

Type the schedule instead of filling in six dropdowns. **"Every second Tuesday at 7pm"**, **"weekdays at 9am until 20 December"**, **"the last Friday of the month"** — each is read back as a plain sentence, with the next ten dates listed, before you save.

* 🗓️ Daily, weekly, monthly by date, monthly by position, and yearly, with an interval, a count, or an end date
* 🧮 **One event, one rule** — the dates are worked out once and kept in an index table
* 🌍 **Daylight-saving safe** — the local time and its zone are stored, not a UTC stamp
* 🚫 **Excluded dates**, and **public holidays** for seven countries, worked out on your own site
* ✂️ **Edit one date, or this and all following** — move, skip, end or split the series, each with exact Undo
* 🕐 **More than one start time a day** — "10am and 2pm tour" is one event with two sessions

= 🗓️ Four calendar views =

* 📅 **Month** — a real HTML table with arrow-key navigation, preview cards on hover **and on keyboard focus**, and "+ N more" on busy days
* 📆 **Week** — seven columns with every event in full; one card per day on a phone
* 📍 **Day** — a single day in full, with paging
* 📜 **List** — grouped by month, with image, venue, summary and tags
* ⚡ **No page reloads**, yet every control is still a plain link or form submit first, so a blocked script changes nothing and the back button keeps working
* 🔎 **Filters** — keyword, category, venue, organiser, format and venue access, each listed above the calendar with a one-click way to drop it
* 🎨 **Colour themes** — your theme, light, dark, the visitor's own setting, or **high contrast**

= 🧩 Blocks, shortcodes and patterns =

Every block works as a shortcode too, so the Classic Editor, page builders, widget areas and theme templates are all covered.

* 📅 **Events Calendar** — the full calendar on any page — `[rayec_calendar]`
* 🗓️ **Mini calendar** — a compact month marking the days that have events — `[rayec_mini_calendar]`
* 📋 **Upcoming events** — the next few, with date badge, time, place and optional image — `[rayec_upcoming]`
* ⏳ **Event countdown** — to the next event or one you pick — `[rayec_countdown]`
* 🧱 **Four block patterns** and **block bindings** (`rayec/event`), so any paragraph, heading or button can show the event's when, where, cost, organiser or programme

= 📥 Import: iCalendar, CSV and The Events Calendar =

Nothing is written until you have read the plan: how many events would be added, how many updated, how many left out, and a row per event saying which it is and why.

* **📆 Any calendar feed or `.ics` file** — from an address, an upload, or pasted text; `webcal://` works. Importing the same calendar again updates those events rather than copying them.
* **📊 A CSV spreadsheet** — the columns are guessed from their names and shown as a table you can correct. Because 3/4/2026 is two different days either side of the Atlantic, the day and month order is a choice on screen, written out in full.
* **🚚 From The Events Calendar** — events, venues (with coordinates) and organisers, with old `/event/…/` links kept working by a 301. Undo removes exactly what the migration made, and their content is never modified.
* **🔄 Follow a calendar** — re-read it hourly, twice a day, daily or weekly and update the events in place. Events that vanish from a feed are left alone, because a broken feed must never empty your calendar.

= 📤 Feeds, subscribe links and SEO =

* 📅 **Subscribe to this calendar** — Google Calendar, Outlook, Apple Calendar or an `.ics` download, keeping whatever filters are in use
* 🏷️ **Filtered feeds** — the iCalendar feed takes a category or a venue
* ➕ **Add to Google Calendar** and a per-event `.ics` on every event page; your RSS feed gains the date and place
* 🧾 **`Event` JSON-LD structured data** with the venue, its coordinates, the organiser and any speakers
* 🗺️ **Sitemap** of the events still worth visiting; every month, week, day and filter address is `noindex, follow`
* 🖼️ **Open Graph and Twitter tags**, and a **1200×630 sharing card drawn for events with no picture**. If an SEO plugin already handles these, this plugin stays out of its way

= 🏛️ Venues, organisers, speakers and programmes =

* 🚪 **Venues with real access details** — step-free entry, accessible toilet, hearing loop, accessible parking, quiet space — plus **"Getting here"** for buses, parking and which door
* 🚩 **Rooms inside venues** — two events in different rooms at the same time are not flagged as a clash
* 🗺️ **Venue maps, three ways** — ask the visitor first, a still picture served from your own site, or written directions
* 👤 **Organisers** with contact links, and **speakers** with photo, role, bio and website
* 🎭 **Programmes** — group events into a festival, course or season, with a public page per programme
* ⚠️ **Clash warnings, never blocks** — the venue, the room and the speaker are checked, and you are told, not stopped

= 🖥️ The dashboard =

* 🏠 **Home** — what is on today, the next seven days, your accessibility score, and the events that need a look
* 🗺️ **Planner** — a month board, a **week board with an hour grid**, and a **by-room board**. Drag to another day, time or room, pull the lower edge to change the length, or use the keyboard. Everything has Undo
* 📋 **Events table** — bulk actions, a column chooser, and **saved views** that remember a tab, a search and your columns
* 🌗 **Light, dark, or match your system**, with a setup guide beside the New event button

= ⚡ Performance, measured =

Figures from a test site holding **10,000 events and 216,528 dates**: **3.5 KB** of script on a calendar page and none at all elsewhere, a **month view in about 140 ms**, and no jQuery.

== What you can run with it ==

* 🧘 **Classes and courses** — yoga, fitness, evening classes, anything that repeats weekly
* ⛪ **Churches, charities and community centres** — services, groups and drop-ins
* 🎓 **Schools, colleges and universities** — term dates, open days and lectures
* 🎪 **Festivals and conferences** — a programme page grouping dozens of events
* 🎭 **Theatres, galleries and museums** — runs of performances, late openings and tours
* 🏢 **Coworking spaces and venues** — rooms inside a building, each with its own board
* 🏃 **Clubs and sports** — fixtures, training nights and socials
* 💼 **Webinars and online events** — joining links and time zones, no venue needed

== Installation ==

= From your dashboard =

1. Go to **Plugins → Add New** and search for **RayEtun Accessible Events Calendar**.
2. Click **Install Now**, then **Activate**.

= Manual upload =

1. Download the plugin ZIP file.
2. Go to **Plugins → Add New → Upload Plugin**, choose the ZIP, and click **Install Now**.
3. Activate the plugin.

= Your first five minutes =

1. Open **Events** in the admin menu. The setup guide opens by itself and asks three questions: how the calendar should open, which day your week starts on, and whether to make a Calendar page. It can make the page for you.
2. Create your first event, or bring events in from **Events → Import**.
3. Add the **Events Calendar** block to any page, or use `[rayec_calendar]`.

"Setup guide" sits beside **New event** in the header, so you can walk through it again whenever you like.

== Frequently Asked Questions ==

= ♿ What does "accessible by default" actually mean here? =

That the accessible thing is the thing that ships, with no setting to find. The month grid is a real HTML table with arrow-key navigation. Every view works with JavaScript switched off. Focus is managed and announced when the calendar changes in place. Reduced motion and forced colours are honoured. The target is WCAG 2.2 AA on every view, and the Accessibility Guard checks your own content against 21 rules on top of that.

= 🔁 Does a repeating event create hundreds of posts? =

No. A series is one event and one rule. The dates are worked out from the rule and kept in an index table for fast lookups, on a rolling 24-month window that tops itself up daily.

= 🌍 What happens to my 6pm class when the clocks change? =

It stays at 6pm. The event stores a local wall-clock time and a named time zone, not a UTC stamp, so a weekly class keeps its hour across daylight saving in either hemisphere. The index keeps UTC alongside it so date-range queries stay fast.

= ✂️ Can I change just one date of a repeating event? =

Yes — move it, skip it, end the series before it, or split the series and change this date and all the ones after. Each has exact Undo.

= 📥 Can I import events from another plugin? =

Yes, two ways. Export a calendar file (`.ics`) from it and Import will read it — that works for any plugin that can export one. If you are coming from **The Events Calendar**, there is a dedicated migration that brings events, venues and organisers across, keeps your old links working with 301 redirects, and can be undone completely. Their content is never modified.

= 📤 Can visitors add an event to their own calendar? =

Yes. Every event page has an "Add to Google Calendar" link and an `.ics` download. The calendar itself has a Subscribe link that opens in Google Calendar, Outlook or Apple Calendar and keeps whatever filters are in use, and you can publish a feed for a single category or venue.

= 🧩 Does it work without the block editor? =

Yes. Every block has a shortcode — `[rayec_calendar]`, `[rayec_mini_calendar]`, `[rayec_upcoming]` and `[rayec_countdown]` — so the Classic Editor, page builders, widget areas and theme templates all work.

= 🗺️ Does the calendar load anything from a map service? =

Not unless you ask it to. Venue maps are set to **ask the visitor first** by default — nothing is loaded until someone presses "Show map". You can switch to a **still picture built on your own server**, in which case the visitor's browser never contacts OpenStreetMap at all, or turn maps off and give written directions instead. See **External services** below.

= 🎨 Will it match my theme? =

By default it takes its colours from your theme and paints nothing of its own. If that does not come out well, choose light, dark, "follow each visitor's own setting", or high contrast — one setting, applied everywhere.

= ⚡ Will it slow my site down? =

The front-end script is 3.5 KB over the wire and only loads on pages that show a calendar. On a test site with 10,000 events a month view renders in about 140 ms. There is no jQuery.

= 🔑 Is anything locked behind a licence? =

No. There is no licence key, no account, and no feature in this plugin that stops working. An optional Pro add-on is planned as a **separate plugin**; nothing here is waiting for it.

= ♻️ What happens if I uninstall? =

Nothing is removed unless you switch on "Delete all events and settings when the plugin is deleted" in Settings. Leave it off and your events survive a reinstall.

= 💬 Get support =

Post in the [WordPress.org support forum](https://wordpress.org/support/plugin/rayetun-events-calendar/). We aim to respond within 24 hours on business days.

== For developers ==

**🔌 REST API** — the whole dashboard runs on it, at `/wp-json/rayec/v1/`.

**⚙️ Hooks (a selection):**

* `rayec_loaded` — add-ons boot here
* `rayec_event_saved( $id )` — after an event is saved and its dates rebuilt
* `rayec_event_data( $data, $post )` — change what an event looks like everywhere
* `rayec_guard_rules( $rules, $event )` — switch Accessibility Guard rules off
* `rayec_guard_issues( $issues, $event )` — add your own checks
* `rayec_register_rest( $namespace )` — add your own endpoints
* `rayec_admin_config( $config )` and the JavaScript `rayec.screens` registry — add your own dashboard screens

Connection points are documented in `includes/class-extensions.php`. The plugin contains **no locked code** — nothing is disabled waiting for a licence key.

= 📦 Source code and build =

The JavaScript in the `build/` folder (the dashboard, the block editor panels and the blocks) is compiled from human-readable source. The full source, including `src/`, `package.json` and the webpack configuration, is public at [github.com/rayetun/rayetun-events-calendar](https://github.com/rayetun/rayetun-events-calendar).

To rebuild it:

1. Install Node.js 20 or later.
2. In the repository folder, run `npm install`.
3. Run `npm run build`. This uses `@wordpress/scripts` and writes the files into `rayetun-events-calendar/build/`.

`npm run start` rebuilds the files as you edit them. The scripts in `assets/js/` are shipped as written and are not compiled.

== External Services ==

This plugin contacts two kinds of outside service, and only when someone on your site asks it to: **OpenStreetMap**, for venue maps and address lookup, and **calendar feeds that you choose** to import or follow. It sends no data about your site or your visitors anywhere else, and collects nothing.

**Calendar feeds you import or follow (administrators only).** On the Import screen you can give the address of a calendar (an `.ics` or `webcal://` link, such as a public Google, Outlook or Apple calendar, or another website's event feed). Your server then downloads that address to read its events. When you choose to **follow** a calendar, your server downloads the same address again on the schedule you pick (hourly, twice a day, daily or weekly) until you remove it. The request goes only to the address you entered, and it sends nothing about your site except what any web request carries (your server's IP address and a user agent naming this plugin). Which service receives the request, and its terms and privacy policy, depend on the address you enter; check them with whoever provides the calendar. Uploading a file or pasting text contacts no one.

**Address lookup (people who manage venues).** When someone presses "Find from the address" while editing a venue, their browser sends that venue's address to Nominatim (nominatim.openstreetmap.org), the OpenStreetMap search service, to find its latitude and longitude. Nothing is sent unless that button is pressed.

**Venue map (visitors).** On an event page the map is not loaded with the page. In the default "ask first" mode it is loaded from openstreetmap.org only after the visitor presses "Show map", at which point the visitor's browser contacts OpenStreetMap, which receives their IP address and the map area requested. In "still picture" mode your own server fetches the map tiles once, stitches them into an image and serves it from your site, so the visitor's browser never contacts OpenStreetMap at all. In "no map" mode nothing is requested.

The address lookup and the maps are provided by the OpenStreetMap Foundation: [terms of use](https://osmfoundation.org/wiki/Terms_of_Use), [privacy policy](https://osmfoundation.org/wiki/Privacy_Policy), [Nominatim usage policy](https://operations.osmfoundation.org/policies/nominatim/).

== Screenshots ==

1. The month view on the front end — a real table with keyboard navigation, category colours, and a preview card on hover and on focus.
2. An event page: the key-facts panel for when, where and who, with add-to-calendar buttons, venue access details and written directions.
3. Week view — seven columns with every event in full, becoming a card per day on a phone.
4. The dashboard Home screen — today, the next seven days, and your site-wide accessibility score.
5. The Planner's week board — drag to another day and time, or stretch to change the length, all with Undo.
6. Plain-language scheduling: type the rule, read it back as a sentence, and check the next ten dates before saving.
7. The Accessibility Guard in the block editor — what is wrong, and what to do about it, beside the Publish button.
8. The Import screen — a calendar feed, a spreadsheet, or a migration from The Events Calendar, with a plan you read before anything is written.
9. The setup guide, which opens the first time you use the dashboard.
10. Mini calendar, upcoming events and countdown blocks on a page.

== Changelog ==

= 1.0.0 =
* 📅 **Calendar views** — month, week, day and list, each responsive, keyboard-navigable and usable with JavaScript switched off. Switching view, month or filter happens in place without a page reload.
* 🔁 **Recurring events** — daily, weekly, monthly by date or by position, and yearly, with intervals, counts, end dates, excluded dates and public holidays for seven countries. A series is one event and one rule, with its dates kept in an index table.
* ⌨️ **Plain-language scheduling** — type "every second Tuesday at 7pm", read it back as a sentence, and check the next ten dates before saving.
* 🕐 **Time zones and daylight saving** — events store a local time and a named zone, so a 6pm class stays at 6pm when the clocks change. More than one start time a day is supported.
* ✂️ **Single-date edits** — move, skip, end the series before a date, or split the series, each with exact Undo.
* ♿ **Accessibility Guard** — 21 checks on every event, each with a line saying what to do about it, in the block editor and before publishing, plus a site-wide score on the dashboard.
* 🏛️ **Venues, organisers, speakers and programmes** — rooms inside venues, real access details, a "Getting here" field, and clash warnings that tell you rather than stop you.
* 🧩 **Four blocks and four shortcodes** — full calendar, mini calendar, upcoming events and countdown — plus four block patterns and block bindings.
* 📥 **Import** — iCalendar feeds and `.ics` files, CSV spreadsheets, and a migration from The Events Calendar that keeps old links working with 301 redirects and can be undone completely. Every import shows a plan before anything is written.
* 🔄 **Followed calendars** — re-read a feed hourly, twice a day, daily or weekly and update the events in place.
* 📤 **Feeds and subscribe links** — iCalendar for the whole calendar or one category or venue, Google Calendar, Outlook and Apple Calendar, and a per-event `.ics` download.
* 🔍 **SEO** — `Event` structured data, an events sitemap, Open Graph and Twitter tags, and a sharing card drawn for events with no picture.
* 🗺️ **Privacy-friendly venue maps** — ask the visitor first, a still image built on your own server, or written directions only.
* 🖥️ **Dashboard** — home screen, a planner with month, week-by-hour and by-room boards with drag, resize and Undo, an events table with bulk actions, a column chooser and saved views, and a setup guide.
* 🎨 **Colour themes** — follow your theme, light, dark, each visitor's own setting, or high contrast.
* ⚡ **Performance** — 3.5 KB of front-end script, no jQuery, and a month view in about 140 ms on a site holding 10,000 events and 216,528 dates.
* 👩‍💻 **REST API and hooks** at `/wp-json/rayec/v1/`, with extension points documented in the source.
* ✅ Checked against the WordPress coding standards and Plugin Check with nothing reported, and against axe on every view and every dashboard screen with nothing reported.

== Credits ==

Map tiles and address lookup are provided by the [OpenStreetMap Foundation](https://osmfoundation.org/) and its contributors, and are used only as described under **External services**.

== More free plugins by the same developer ==

RayEtun Accessible Events Calendar is part of a family of free WordPress plugins built by the same independent developer:

* **TrustBio** — show who wrote and who reviewed every post: verified author credentials, reviewer bylines and author schema. [View on WordPress.org](https://wordpress.org/plugins/trustbio/)
* **PixelVault** — organise your media library with nested drag-and-drop folders, then publish any folder as a downloadable Document Library. [View on WordPress.org](https://wordpress.org/plugins/pixelvault/)
* **PinSpot** — turn any image into an interactive map with clickable hotspots, great for floor plans, product tours and infographics. [View on WordPress.org](https://wordpress.org/plugins/pinspot/)
* **RayEtun Media Protection** — watermark your images and PDFs, protect downloadable files, and control who can access them. [View on WordPress.org](https://wordpress.org/plugins/rayetun-media-protection/)
