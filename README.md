This is very much BETA right now, go ahead and use it, but I make no guarantees about anything.
However, you will be helping me test things :)

This branch supports CiviCRM versions 4.2 - 4.4. For a period there were parallel developments and those
have been merged into this branch - making it quite different from the other branches. They are no longer
being developed. It may be necessary to uninstall those versions to install this as it is a 'module' extension
- if you need to do so you should alter the 'name' of the eway recurring processor in the 'payment_processor_type'
table. After it is installed remove the new entry & name the original one back.

Updates are done via scheduled jobs - depending on your version this will be automatically added to the scheduled jobs page

If you wish to query the details of existing tokens there is an api to do that. However, you may wish to run if from this extension which also stores the details (e.g expiry date & masked credit card, which are OK to store if you are not storing card details) https://civicrm.org/extensions/payment-tokens

This extension has quite a history, this documentation will be fleshed out in the future, but it wouldn't have been possible without the efforts and backing of : Voiceless (Sponsor), Community Builders, The Australasian Tuberous Sclerosis Society (Sponsor), Henare Degan, RIGPA (Sponsor), Ken West and Eileen McNaughton.
