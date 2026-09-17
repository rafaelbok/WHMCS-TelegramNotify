# WHMCS-TelegramNotify
Send WHMCS notifications to telegram

## Installation
1. Place the Telegram folder in your WHMCS installation (modules/notification/Telegram)
2. Create a bot with [BotFather](https://telegram.me/BotFather "BotFather"), and get the token.
3. Send a message to your new bot from the user/channel on which you want to receive notifications.
4. Go to this URL: https://api.telegram.org/bot[TOKEN]/getUpdates
(Replace[TOKEN] with your bot token)
5. Get your chat ID
6. Go to your WHMCS administration panel, Setup > Notifications, click on the Configure button under Telegram, and put your bot token and chat ID, you should receive a message "Connected with WHMCS", otherwise, check your chat ID and token.

## Delivery retries

The module makes at most three send attempts (the original request plus two retries) only when a connection transfer fails, Telegram returns HTTP `429`, or Telegram returns HTTP `5xx`. For `429` responses, it waits for the number of seconds in Telegram's `parameters.retry_after`; other transient failures use a bounded incremental delay with jitter. Permanent HTTP `4xx` responses, such as an invalid token, chat ID, or message content, are not retried.

Each transient failure is logged with its attempt number and category, without the bot token, request URL, or message content. Retrying after a connection timeout can cause a duplicate notification: Telegram may have accepted the request even though the client did not receive its response. Configure downstream handling with that possibility in mind.

