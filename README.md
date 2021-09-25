# Stripe x Wordpress Webhook integration
*by @samboyer / Oxford Recording Society*

This pluign exposes an API endpoint on your Wordpress site that can be given to
Stripe as a webhook receiver. Currently the only supported behaviour is
* Approve membership status of a Pending account whose email address matches the
email field in a payment_intent.succeeded event.

but this could be extended to support a whole range of actions!

## Installation/Setup
* Clone this repo into your wp-content/plugins directory
* Paste your Stripe 'webhook signing secret' key (which you can create
[here](https://dashboard.stripe.com/webhooks/create)) into SECRET_KEY file (L20)

## To-do
* Move the constants (secret key, product ID) to the database, and edit them via
 a settings page
* Verify the product ID so we can support multiple Products in the future
(need to send multiple tests & cross-reference)
