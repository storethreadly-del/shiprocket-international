# Request to iCarry.in support: shipment API documentation

Send as a helpdesk ticket (https://www.icarry.in/tickets) or to support@icarry.in.

---

**Subject:** Shipment API documentation for a WooCommerce integration (account: <your iCarry username>)

Hello,

I'm integrating my WooCommerce store with iCarry.in through your shipment API. I have generated API credentials under My Account > API Credentials (a username and an API key). Your page "Shipping APIs, Plugins & Extensions" lists the REST endpoints, but not their URLs or parameters. Could you please send the full API documentation?

Specifically, for each of these endpoints:

- Authentication, and LOGIN
- Get ESTIMATE: domestic, by courier, and international
- Book Shipment: standard/express, choose courier, and international
- CANCEL Shipment, and REVERSE Shipment
- TRACK a Shipment, SYNC Shipment STATUS, SYNC Shipment CHARGES
- POST Call Back on change in Shipment STATUS (the webhook)
- PRINT Shipment Label
- Check SERVICEABILITY by PINCODE
- Pickup Address: add, update, and list

please share:

1. The base URL and the exact path of each endpoint.
2. How requests are authenticated with my username and API key (header, token exchange, or body fields), and any token lifetime.
3. Request parameters, with required and optional fields and their allowed values (for example mode, parcel type, units).
4. Example requests and responses, including error responses.
5. Whether a sandbox or test mode exists, so I can test without creating real shipments or spending wallet balance.
6. The webhook payload format, how it is signed or verified, and your retry behaviour.
7. Rate limits and any lockout rules for failed authentication.
8. Whether I need to register pickup, shipper and return addresses first, and how to get their ids.

I'd also like to confirm that automated use of the API with these credentials is permitted for my account.

Thank you,
<your name>
