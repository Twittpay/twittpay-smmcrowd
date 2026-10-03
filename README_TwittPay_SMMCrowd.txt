===========================================================================
 TWITTPAY - SMMCrowd payment gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your SMMCrowd root - the folder that has application/ in
   it. This file lands in place:

     application/app/Http/Controllers/Gateway/TwittPay/ProcessController.php

   database.sql, twittpay-logo.png and this README sit at the top of the zip.
   They are not part of the panel - delete them from the server after you are
   done, or do not upload them at all.

 INSTALL - FOUR STEPS

   1. Upload and extract at the panel root as above. The folder name must stay
      exactly TwittPay - the class namespace and the route both use it.

   2. Import database.sql in phpMyAdmin, into your panel's database. It adds one
      row to the `gateways` table and touches nothing else. The row is added
      switched off on purpose. Read the note at the top of the file if phpMyAdmin
      reports a duplicate key.

   3. Open application/routes/ipn.php and add this line at the end of the file:

        Route::any('twittpay', 'TwittPay\ProcessController@ipn')->name('TwittPay');

      Keep it next to the other gateway lines in that file, inside the same
      group. That is the address your gateway's webhook calls.

   4. Admin -> Payment Gateways -> open "Bkash/Nagad/Rocket/Upay" and fill in:

        Endpoint URL      your own gateway address, e.g.
                          https://checkout.twittpay.com
                          (the API host shown on your gateway's developer page)

        Brand Key           from your gateway dashboard, under Brands

      Add the BDT currency with its conversion rate, minimum and maximum, upload
      twittpay-logo.png as the gateway image, switch the gateway on, and make
      a small test deposit.

 HOW IT WORKS
   * The user picks the method, types an amount and pays. The panel works out the
     BDT amount from the conversion rate in the gateway's own currency settings,
     so the amount sent is already in BDT.
   * Both the returning user and the gateway's webhook come back to the same ipn
     route. The webhook gets a short text answer; the user is sent back into the
     panel.
   * Nothing on that request is trusted. The transaction id is read from it and
     the payment is then verified against the API.
   * COMPLETED credits the deposit, but only while it is still uncredited - so the
     webhook and the return cannot credit it twice.
   * PENDING credits nothing. The user has sent the money and your merchant has
     not approved it. The gateway calls again with the answer, and that call
     credits the balance. Do not ask them to pay twice.
   * A payment worth less than the deposit is refused.
   * The deposit reference is written into the payment's metadata, so a payment
     can only ever be matched back to its own deposit.

 WHAT TO WATCH
   * The Endpoint URL is your API host. Pasting the whole endpoint or a trailing
     /api is fine - only the scheme and host are used.
   * The ipn route must be reachable from the internet. Your gateway's server
     calls it directly.
   * This module sends the user back to the ipn route as well, so a deposit still
     completes if the webhook cannot get through.
   * The gateway only takes BDT. That is why BDT is the only supported currency in
     database.sql - keep its conversion rate up to date.
   * If your panel's CSRF filter covers the ipn routes, the webhook will be
     refused. On a stock SMMCrowd the ipn routes are already excluded; if yours
     is not, add the ipn path to $except in
     application/app/Http/Middleware/VerifyCsrfToken.php.
   * Refunds are not done through the API. Refund on the gateway side, then adjust
     the user's balance by hand.

 FIXES OVER THE ORIGINAL
   * The PipraPay version read the transaction id only from the raw request body,
     which is empty when the user comes back with a GET. The id is now read from
     the query, a form body or a JSON body.
   * The original looked the gateway settings up but never checked that the
     payment's metadata pointed at the deposit it was crediting. It does now.
   * The original never compared the amount paid with the amount due. It does now.
   * A pending payment was reported as a failed verification. It is now left alone
     for the next webhook.
   * The original passed the raw API error message back to the user. An error
     string can carry your Brand Key back out, so this port shows a plain message.
   * The API URL is shipped empty. The original shipped a sandbox address as its
     default, which is easy to leave in place by accident.
   * The gateway row is inserted switched off, so a half-configured gateway cannot
     appear on your deposit page.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging install first.
