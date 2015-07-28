This is very much BETA right now, go ahead and use it, but I make no guarantees about anything.
However, you will be helping me test things :)

This branch supports CiviCRM versions 4.4+ For a period there were parallel developments and those
have been merged into this branch - making it quite different from the other branches. They are no longer
being developed. It may be necessary to uninstall those versions to install this as it is a 'module' extension
- if you need to do so you should alter the 'name' of the eway recurring processor in the 'payment_processor_type'
table. After it is installed remove the new entry & name the original one back.

Updates are done via scheduled jobs - depending on your version this will be automatically added to the scheduled jobs page

If you wish to query the details of existing tokens there is an api to do that.

However, you may wish to run if from this extension which also stores the details https://civicrm.org/extensions/payment-tokens
(e.g expiry date & masked credit card, which are OK to store if you are not storing card details)

Extension APIs - use api getfields function to find out more about these

  Single Interaction Tokens

    ewayrecurring.payment
    ewayrecurring.querytoken
    ewayrecurring.query_payment

  Scheduled Job Token
    job.eway


-- Setting up an account for tokens ---

To use tokens you need to set up an  API Password.


1. Login to MYeWAY and navigate to My Account > User Security > Manage Roles

2. Create role under name of Token API

3. Navigate to My Account > User Security > Create User

4. Assign Token role to new user.

5. Name new user 'API KEY' and put in an email address.

Eway recommend something along the lines of api@yourdomain.com.
Then create a Password.
Please note: The email address you use does not have to be real, as we will never send anything to it, but it does have to be unique.
The password you create for this user will be your API Password and will be what you enter into the code.

You should enter this password into your payment processor settings along with the email address you created for it.
The 'subject' field is the customer number.

This extension wouldn't have been possible without the efforts and backing of : Voiceless (Sponsor), Chris Chinchilla, 
Community Builders, The Australasian Tuberous Sclerosis Society (Sponsor), Henare Degan, RIGPA (Sponsor), 
Ken West and Eileen McNaughton.

