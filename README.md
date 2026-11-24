# Cyfrowe Sylius NPS Plugin

A Sylius plugin that measures customer satisfaction with the Net Promoter Score. A set number of
days after an order ships - or after your own code says it was delivered - it emails the customer a
single-question survey on a 0–10 scale, records the score the
moment a number is clicked in the email, collects an optional comment, and reports the result in
the admin panel.

## Requirements

| | |
| :---- | :---- |
| PHP | ^8.2 |
| Sylius | ~1.14.0 |
| Symfony | ^6.4 |
| Database | MySQL 8 or MariaDB 10.11+ (PostgreSQL is not supported) |
| Also | a scheduler for two console commands (cron is enough) |

A message queue is optional: without one the emails are sent straight from the command.

## Installation

### 1. Install the package

```bash
composer require cyfrowe/sylius-nps-plugin
```

Register the bundle in `config/bundles.php`:

```php
Cyfrowe\SyliusNpsPlugin\CyfroweSyliusNpsPlugin::class => ['all' => true],
```

### 2. Import the routes

In `config/routes.yaml`:

```yaml
cyfrowe_sylius_nps_shop:
    resource: "@CyfroweSyliusNpsPlugin/config/shop_routing.yml"
    prefix: /{_locale}
    requirements:
        _locale: ^[A-Za-z]{2,4}(_([A-Za-z]{4}|[0-9]{3}))?(_([A-Za-z]{2}|[0-9]{3}))?$

cyfrowe_sylius_nps_admin:
    resource: "@CyfroweSyliusNpsPlugin/config/admin_routing.yml"
    prefix: /%sylius_admin.path_name%
```

The locale prefix opens the survey in the language it was sent in. Drop it if your shop routes
have none, and otherwise keep the `_locale` requirement the same as on your `sylius_shop` routes: a
narrower one cannot build the link for an order in a locale outside it, and that email is never
sent.

### 3. Clear the cache, create the tables and install the assets

```bash
bin/console cache:clear
bin/console doctrine:migrations:migrate
bin/console assets:install public
```

Clear the cache first: with debug off, Symfony does not notice the new routes, and the admin panel
fails on the NPS menu until the cache is rebuilt.

### 4. Decide when a survey starts

Pick one:

* **After shipping** - the default. A survey starts once every shipment of the order has been
  shipped, with nothing to configure.
* **From your own code**, when your shop knows the order reached the customer. Switch the shipment
  trigger off and call the package yourself - see [Starting a survey](#starting-a-survey).

### 5. Give the emails a queue (optional, recommended)

Add a bus and a transport with retries in `config/packages/messenger.yaml` and point the plugin at
them. The database is enough; RabbitMQ or Redis work the same way with their own DSN:

```yaml
framework:
    messenger:
        buses:
            nps.bus: ~
        transports:
            nps:
                dsn: 'doctrine://default?queue_name=nps'
                retry_strategy:
                    max_retries: 3
                    delay: 600000   # 10 min, then 30 and 90
                    multiplier: 3

cyfrowe_sylius_nps:
    messenger:
        transport: nps
        bus: nps.bus
```

The bus of its own matters: the default Sylius bus saves the survey only after the email has gone
out, so a failed save there is retried and the customer gets the email twice.

Keep a worker running on it:

```bash
bin/console messenger:consume nps
```

Without a transport the emails go out straight from the command in step 6, and a failed one waits
for its next run. See [The queue](#the-queue) for what is retried and what is not.

### 6. Schedule the commands

```cron
0 * * * *    cd /path/to/shop && bin/console nps:survey:send       # sends the surveys that are due
*/5 * * * *  cd /path/to/shop && bin/console nps:notify:critical   # alerts about low scores
```

Cron or the Symfony scheduler - whichever the shop already uses.

### 7. Set up each channel

* In **Channels**, give every channel its **hostname** (`shop.example.com`). The links in the
  emails are built from it.
* In **NPS > Settings**, save the settings of every channel and fill in the address for alerts.

A channel without saved settings gets no surveys.

Sylius also matches the hostname against the host of every request, without the port. In a shop
with more than one channel, a hostname with a port therefore matches nothing and every shop page
fails. A single-channel shop falls back to its only channel, so a local setup on a non-standard
port can keep the port in the hostname (`localhost:8080`) and get working links; with several
channels, run it on port 80 or add the port to the links by hand.

### 8. Brand the emails

Out of the box the emails use the Sylius email layout: the Sylius logo and a link to sylius.com in
the footer, or your own branding if the shop already overrides that layout
(`templates/bundles/SyliusCoreBundle/Email/layout.html.twig` or
`templates/bundles/SyliusShopBundle/Email/layout.html.twig`). To frame them differently, point the
plugin at another layout:

```yaml
cyfrowe_sylius_nps:
    email:
        layout: 'email/layout.html.twig'
```

It has to define a `body` block that renders a `content` block, as the Sylius layout does: the
Sylius mailer renders `body`, and the plugin's emails fill `content`. A layout without them fails
only when an email is sent. You can also edit the emails in your mail template tool - see
[The email](#the-email).

### 9. Check it

On **NPS > Settings**, **Send test email** sends the survey email to the address you type.

## Starting a survey

Out of the box a survey starts once every shipment of an order has been shipped - see
[The reference trigger](#the-reference-trigger). Shipping is not delivery, though, and every shop
learns that an order reached the customer from a different place - an ERP message, a carrier's
tracking, an external logistics system - so a shop with a signal of its own starts the survey
itself.

Switch the reference trigger off first, in `config/packages/cyfrowe_sylius_nps.yaml`:

```yaml
cyfrowe_sylius_nps:
    shipment_trigger: false
```

Left on, it starts the survey at shipping - also for orders your code would leave out - and your
own call then finds that survey and, while it waits to be sent, restarts its delay. With the flag
off, none of its services are registered at all.

Then call the package's one entry point:

```php
use Cyfrowe\SyliusNpsPlugin\Survey\SurveyStarterInterface;

public function __construct(private readonly SurveyStarterInterface $surveyStarter)
{
}

$survey = $this->surveyStarter->start($order);
```

It returns the survey - freshly created, or the one that already existed for that order - or `null`
when the order has no channel, or its channel has no saved NPS settings or has the survey switched
off.

Three things to know before wiring it in:

* **A repeated call restarts the delay.** While the survey waits to be sent, every call moves its
  qualifying moment to now. Call it once per order, when the order really reached the customer.
* **It flushes the entity manager.** Whatever the caller has pending is written out as well.
* **Call it from somewhere that can retry.** One survey per order is enforced by a unique
  constraint, and a concurrent call loses that race with an exception. The exception is left to
  surface on purpose: swallowing it would hand back a closed entity manager and break the caller's
  own write somewhere unrelated. On the retry the existing survey is found and returned, so a
  message handler is the natural place for the call.

### The reference trigger

The trigger the package ships with serves a shop with no delivery signal of its own. It starts a
survey once every shipment of an order that still counts has been shipped, ignoring cancelled ones,
and stays on until `shipment_trigger` is set to `false`.

It works under both state machine adapters Sylius supports - it registers a `winzou_state_machine`
callback and listens for `workflow.sylius_shipment.completed.ship` - so it does not matter which
one the shop runs.

## Sending the survey email

A record created by `start()` waits for the delay configured on its channel and is then sent by
a command:

```bash
bin/console nps:survey:send [--limit=500] [--channel=CODE] [--dry-run]
```

The package ships the command, **not a schedule**. Run it from cron or the Symfony scheduler,
whichever the shop already uses. The recommendation is **hourly**: the command only
acts on the channels whose sending window it currently is, and running it every hour means one
failed run delays the send by an hour rather than by a day.

The window is set per channel in whole hours, from 10 to 20 by default. It opens at the first hour
and closes at the second: with 10 to 20 the last email goes out before 20:00. A survey that falls
due outside the window waits for it to open. A window does not run past midnight; 0 to 24 sends
round the clock.

The hours are those of PHP's time zone (`date.timezone`) - Sylius keeps none of its own. Set it to
the shop's zone, the same for the command line and the web server, since the dates the package
stores use it too.

`--dry-run` reports exactly what a real run would dispatch, the sending window included, and
changes nothing.

Two runs cannot overlap: the command takes a lock first. Nothing has to be configured for that to
work, but the default store is a file on the machine running the command. A shop with several
application servers needs a shared one:

```yaml
cyfrowe_sylius_nps:
    lock:
        dsn: 'redis://localhost'
```

### The queue

Each due survey is dispatched as a `Cyfrowe\SyliusNpsPlugin\Message\SendSurvey` message carrying
only an id, and the handler reads the record back before sending. Left alone, the message is routed
like any other in your application; `messenger.transport` gives it a queue of its own (step 5).

Retrying is Messenger's job, so give the transport a retry strategy. A message that fails before
the email goes out - the mail server refused it, the database hiccuped - leaves the survey in
`QUEUED`, and a retried message sends it. Once the retries run out it lands in the failure
transport and the survey stays in `QUEUED` for review; `messenger:failed:retry` is safe for it.
The one exception is a survey that went out but whose state could not be saved: it fails as
unrecoverable, is never retried, and says so in its error. Retrying it by hand sends the email a
second time.

A survey that cannot be sent is not silently dropped: it goes to the `SKIPPED` state with the reason
recorded - no recipient, cooldown, channel disabled, channel removed, order cancelled or order gone.

### The email

The package ships a working template, so nothing needs filling in after installation. The subject
and the questions come from the channel's settings in the admin panel; everything else comes from
translations. They ship in English and Polish; any other language falls back to English on a stock
Sylius install, or gets its own `messages`, `flashes` and `validators` files in the shop's
`translations/` directory.

The layout the email is wrapped in comes from configuration and defaults to the stock Sylius email
layout:

```yaml
cyfrowe_sylius_nps:
    email:
        layout: '@SyliusShop/Email/layout.html.twig'
```

The default follows the Sylius email layout, including any override of it in `templates/bundles/`.
Point it at another Twig layout to frame the NPS emails differently (step 8 says what it needs); a
shop whose branding lives outside Twig replaces the emails in its mail template tool instead
(below). To replace the email itself rather than its frame, override
`@CyfroweSyliusNpsPlugin/email/survey.html.twig` the way you would any bundle template; keep
`{% extends layout %}` and fill the `subject` and `content` blocks.

A mail template tool (such as BitBag MailTemplate) can replace either email by its code:

| Email | Code | Variables |
| :---- | :---- | :---- |
| Survey | `cyfrowe_nps_survey` | `survey`, `customer_first_name`, `contents` (the channel's wording), `channel`, `localeCode`, `score_routes` |
| Critical score alert | `cyfrowe_nps_critical_alert` | `survey`, `kind`, `subject_key`, `lead_key`, `final`, `channel`, `localeCode`, `card_route` |

The score buttons have to keep this loop, or the scores never reach the survey:

```twig
{% for score, parameters in score_routes %}
    <a href="{{ sylius_channel_url(path('cyfrowe_nps_shop_survey_score', parameters), channel) }}">{{ score }}</a>
{% endfor %}
```

In the alert, the link to the response is
`sylius_channel_url(path('cyfrowe_nps_admin_survey_show', card_route), channel)`. It is built on the
channel's hostname, so it assumes the admin panel answers there too; a shop with the admin panel on
another host overrides `@CyfroweSyliusNpsPlugin/email/critical_alert.html.twig` or replaces the
email by its code.

The eleven score links are built from the channel's own hostname, so the survey opens on the domain
the customer ordered from.

### Checking a change before it goes out

The settings screen has a **Send test email** button next to an address field. It sends the survey
email built from the wording currently on screen - saved or not - so a change to a question can be
checked without placing an order. The links in a test email point at a token that belongs to no
survey, so they cannot score a customer's - they open the page for an unrecognised link.

## Survey pages

The package ships the shop routes the survey link points at, wrapped in your storefront layout:

```yaml
cyfrowe_sylius_nps:
    shop:
        layout: '@SyliusShop/layout.html.twig'
```

The survey templates extend that layout and override three of its blocks, so a layout of your own
has to define them: **`content`** for the page itself, **`metatags`** for the `robots` tag and
**`javascripts`** for the script below. A layout missing one of them fails to render.

The link has to resolve to a host one of your channels answers on - the pages sit in the storefront
layout, which needs a channel for the request.

### Keeping scanners out of the results

Scoring happens on a `GET` request, and mail filters follow those links on the customer's behalf.
Requests that announce themselves as a prefetch, carry a known scanner signature, work through too
many of the eleven links at once, or come from one address faster than 60 a minute leave no score
behind - the record is flagged instead, and the customer can still pick a number themselves.

The address is the client's as Symfony sees it, so behind a reverse proxy or CDN configure
`framework.trusted_proxies` - otherwise every customer shares the proxy's address and its limit.
The limits are counted in `cache.app`, which several application servers have to share.

Mail filters differ between shops, so the signature list is yours to set:

```yaml
cyfrowe_sylius_nps:
    shop:
        bot_user_agents: ['bot', 'crawler', 'spider', 'scan', 'preview', 'proofpoint', 'barracuda', 'mimecast']
```

The survey pages also carry `X-Robots-Tag`, `Referrer-Policy: no-referrer` and a `robots` meta tag,
so a link with a token in it stays out of search results and out of the `Referer` header.

### Front-end assets

The survey page swaps the follow-up question when the customer changes their score. It is a plain
browser script shipped as a bundle asset, installed by `assets:install` in step 3, so **there is no
build step to add**. With the script blocked or missing, the page still shows the wording that
matches the score already saved - the swap is an enhancement, not a dependency.

## In the admin panel

The plugin adds an **NPS** section to the admin menu with two entries: **Responses** and
**Settings**.

### The list of responses

Every survey the shop has created, newest score first, twenty-five to a page, or 50 or 100 if
chosen. The columns are the date the score was given, the score, the band it falls in, the
customer, the order, the shipping method, the survey status, the handling status and the comment.
All of them sort.

The list holds every survey, answered or not, so a survey nobody replied to is visible rather than
missing. That includes surveys never sent - still waiting for their delay, or skipped - which is
why the list is longer than the number sent. The survey status column tells them apart (pending,
queued, sent, scored, completed, skipped); the handling status is a separate thing, set by staff.
**Scored: yes** narrows the list to the ones with a score.

Above the list sit the figures for whatever the list is currently showing:

* the NPS index, or a dash when the filters matched no answers - zero is a real result and must not
  be confused with an empty set,
* how many answered, and how many of them are promoters, passives and detractors, with shares,
* the average score and the share of answers carrying a comment,
* how many surveys went out, were scored and were completed - three numbers that differ, because
  most people who score never open the form,
* the response rate and the completion rate - scored and completed as shares of those sent, or a
  dash with a hint when the filters in use make them meaningless (see below). Surveys whose link
  is still valid may yet be answered, so a period that ends recently reads low,
* the spread of scores across the whole 0-10 scale.

They are worked out by the database in one pass over the same set the list is showing, on the same
filters - plus a single count when the rates have to be checked, as described below. Narrowing by
shipping method therefore reports the index for that method alone, which is how a shop that models
pickup points or stores as shipping methods gets a figure for each without the package knowing what
they are.

The filters are: a search over the customer's email and the order number, the date the score was
given, the date the survey was sent, a score range, the band, the channel, the shipping method, the
survey status, the handling status, whether there is a comment and whether there is a score. Both
statuses accept several values at once. The channel and shipping method lists are built
from the records rather than from the shop's catalogue, so a method retired last year still filters
the history that mentions it.

Filters live in the address, so a link reproduces the view for whoever you send it to.

#### The response rates and the filters

A rate is only as good as its denominator. Filtering by the date sent, the channel, the shipping
method or the search chooses **which surveys went out**, and the rates for that set are true.
Filtering by the score, the band, the date it was given, the comment or a status chooses surveys
**by their answer**: keep the answered ones and the rate reads 100%, keep the rest and it reads
0% - a number decided by the filter, not by the customers.

So the rates are shown only while the filters that do not choose which surveys went out leave
every sent survey in place. Leaving out surveys that were never sent is harmless - a status filter
that keeps sent, scored and completed still shows the rates - but leaving out even one sent survey
replaces them with a dash and a hint. The check compares the list with the same list narrowed by
the population filters alone, defaults of the grid included, and costs a second query only when a
filter other than those actually narrowed the list.

Which filters choose the population is set per filter in the grid, with the `keeps_rates` option.
The package marks `search`, `sentAt`, `channelCode` and `shippingMethodName`. A filter without the
option - including any a shop adds - counts as one that may pick by the answer, so the rates are
withheld rather than wrong until someone decides otherwise. To mark a filter of your own, for
example a pickup point:

```yaml
sylius_grid:
    grids:
        cyfrowe_nps_admin_survey:
            filters:
                pickupPoint:
                    type: string
                    label: app.ui.pickup_point
                    options:
                        fields: [shippingMethodCode]
                        keeps_rates: true
```

Sylius replaces a filter's `options` as a whole rather than merging them, so when you override the
options of one of the package's own filters, repeat `keeps_rates: true` - otherwise that filter
starts withholding the rates.

### One response in full

The **Show** button on a row opens that response in full: the score and its band, the customer,
the order number with a link to the order, the value, the shipping method, the channel, the survey
status with the reason it was skipped where it was, the dates, the main question and the whole
comment - the list truncates it, and a critical score is exactly the case where the rest of the
sentence is the point.

The question is the channel's current wording in the survey's language, not a copy kept with the
response: after the wording changes, older responses show the new question rather than the one
the customer saw.

Next to it is the handling section: a status (new, in progress, handled), an internal note, and who
dealt with it. The person is taken from whoever is signed in rather than typed, because the point
of the field is who actually did it.

### The settings screen

One configuration per sales channel. It carries whether surveys are sent at all, how many days
after the order qualifies, how long a customer is left alone after one survey, how long a link
stays valid, the hours of the day to send between, the wording in each of the channel's languages,
and the alerts about critical scores - whether they are sent, the address, the threshold and the
grace window. The **Send test email** button sits here too - see
[Checking a change before it goes out](#checking-a-change-before-it-goes-out).

**None of it has a default tied to any particular shop.** The notification address in particular
starts empty: no package can guess where a shop reads its complaints.

## Exporting the responses

The **Export CSV** button above the response list writes the file the list is currently showing -
the same filters, the whole set rather than the page being read. A file that ignored the filters on
screen would be the classic trap: September on the screen, everything in the file.

Thirteen columns: the date the score was given, the score, the band, the customer's address, the
order number, the order value, its currency, the shipping method, the channel, the comment, the
date the survey was sent, the handling status and the survey status. The headers come from translations, so they
follow the language of the panel.

The file is written as it is read, in batches, so its size is bound by the number of records rather
than by the memory of the process.

The dialect is configurable, because no single one works everywhere:

```yaml
cyfrowe_sylius_nps:
    export:
        csv:
            delimiter: ','
            enclosure: '"'
            decimal_separator: '.'
            bom: false
```

Those defaults are RFC 4180, which is what travels between tools. Excel on a locale whose list
separator is a semicolon needs something else, and it reads the file as UTF-8 only when it starts
with a byte order mark - a mark that most other tools read as a stray character at the top of the
first cell. For that case:

```yaml
cyfrowe_sylius_nps:
    export:
        csv:
            delimiter: ';'
            decimal_separator: ','
            bom: true
```

Each of the three characters has to be exactly one character, and the separator cannot be the same
character as the enclosure. The container refuses to build otherwise - the alternative is a failure
halfway through a download, with the headers already sent and a truncated file already saved.

Values that could be read as a formula - anything opening with `=`, `+`, `-`, `@`, a tab or a
carriage return - are prefixed so that a spreadsheet treats them as text. The comments in this file
are written by customers, and the person opening it works at the shop.

## Alerting on a critical score

A score at or below the channel's threshold is worth somebody's attention the same day. The
package sends that alert to the address on the channel's settings screen - it has no default,
because no package can guess where a shop reads its complaints.

```bash
bin/console nps:notify:critical [--limit=200] [--dry-run]
```

As with the survey itself, the package ships the command and **not a schedule**. The
recommendation is **every few minutes**: whatever the interval is, it is added to the grace window
below before an alert appears, so an hourly run turns a half-hour window into an hour and a half.
Two runs cannot overlap - the command takes a lock first, configured the same way as the sending
one above.

`--dry-run` lists every alert a real run would send and changes nothing, neither the emails nor the
marks on the records.

### Why the alert waits

A score can change between the click in the email and the form being submitted, so "email somebody
when a score is low" is not one instruction but two, and both of the simple readings are wrong.
Alerting the moment somebody clicks means false alarms from a customer who hit 2 by accident and
corrects it to 8 - and an alert with no comment in it, so nobody knows what to react to. Alerting
only once the form arrives means alerting almost never, because most detractors never fill it in.

So an alert goes out when **either** of these is true:

| | When | What the alert says |
| --- | --- | --- |
| **The window closed** | the configured number of minutes has passed since the score last changed and no form has arrived | the score, marked provisional, with a note that no comment was left |
| **The form arrived** | the customer submitted the form and the final score is at or below the threshold | at once, with the comment |

The window is counted from the last change to the score, so going back to the email and clicking a
different number restarts it.

### What happens after an alert

Two things can still change, and each is worth one short message:

- the customer puts the score **above** the threshold - the alert is followed by a note saying so,
  which saves somebody a call to a customer who is no longer unhappy,
- the customer submits a form that brings a **comment** the alert went out without.

Either is sent once. A form that does both - a better score and a comment - gets the correction
only: one event, one message.

### What never stops an alert

A record flagged as possibly automated still alerts. The flag exists to keep mail scanners out of
the figures, and a customer whose record a scanner touched can still go on to leave a real 1. The
flag appears in the alert as a note and never in the decision to send it.

An address the mail server refuses is counted instead. After three failures in a row the record
stops being picked up, with the failure in the log each time. The admin panel shows neither the
count nor the alert marks. To try again once the address is fixed, zero the count in the database -
the record's alert mark is still empty, so the next run picks it up:

```sql
UPDATE cyfrowe_nps_survey SET critical_attempts = 0 WHERE id = 123;
```

## Development

Everything runs in Docker — the package needs PHP 8.2+, and the image brings it along with
a Sylius test application, so nothing has to be installed on the host.

```bash
make init     # build the image, install dependencies, set up the test shop
make up       # start the containers
make down     # stop them
```

`make init` takes a few minutes the first time — it builds the image, installs the dependencies,
sets up the database, loads the fixtures and compiles the assets.

### Opening the test shop

| | Address | Credentials |
| :---- | :---- | :---- |
| Shop | http://localhost:8080 | — |
| Admin panel | http://localhost:8080/admin | `sylius` / `sylius` |
| Customer account | http://localhost:8080 | `shop@example.com` / `sylius` |

Links in emails from the test shop point at `http://localhost/`, without the port, because that is
the fixture channel's hostname. Add `:8080` by hand, or change the hostname to `localhost:8080` -
it is the only channel, so the shop keeps working.

Nothing starts on its own after a reboot unless Docker is set to start with the system — run
`make up` first, and `make logs` if a page does not come up.

### Day-to-day commands

```bash
make ecs                  # coding standard
make phpstan              # static analysis, level 8
make static               # ecs + phpstan
make phpunit              # unit tests
make phpunit-integration  # integration tests, against the test database
make phpunit-functional   # functional tests over HTTP, against the test database
make behat                # Behat scenarios
make ci                   # everything CI runs, from a fresh make init
make sh                   # a shell inside the container
make db                   # a MySQL console on the development database
make logs                 # follow the application container's logs
make destroy              # stop the containers and delete the database volume
```

Each of them runs inside the container, so `make phpunit` on the host does the right thing.

### Configuration

`make .env` writes an `.env` from `.env.dist`, filling in your own user and group ids. Everything
the container writes into the working tree — `vendor/`, caches, generated assets — is therefore
owned by you, not by root, on Linux as well as macOS.

| Variable | Default | Purpose |
| :---- | :---- | :---- |
| `UID` / `GID` | your own ids | who owns the files the container creates |
| `PHP_VERSION` | `8.2` | PHP inside the container; the lowest version the package supports |
| `HTTP_PORT` | `8080` | the test shop; 80 is left free for other projects |
| `MYSQL_PORT` | `3307` | MySQL; 3306 is left free for other projects |

To check the package against a newer PHP, set `PHP_VERSION=8.3` in `.env` and run `make build`
again. CI runs PHP 8.2 and 8.3 on MySQL 8.4, and PHP 8.2 on MariaDB 10.11, on every pull request
and every push to `develop` and `master`.

## License

MIT - see [LICENSE](LICENSE).
