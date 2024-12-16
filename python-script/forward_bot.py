from telethon import TelegramClient, events

# Replace with your Telegram API credentials
api_id = '15338268'
api_hash = '6e0c5aa5df9a760e77ce9306320423b7'

# Replace with the source and target channels
source_channels = ['@nonadminchanneld']  # Channels to monitor
target_channel = '@DirectamCryptobot'  # Channel to forward messages to

# Initialize the Telegram client
client = TelegramClient('channel_watch', api_id, api_hash)

# Event handler for new messages
@client.on(events.NewMessage(chats=source_channels))
async def handle_new_message(event):
    print(f"New message detected in {event.chat_id}: {event.message.text}")
    await client.send_message(target_channel, event.message)

# Event handler for edited messages
@client.on(events.MessageEdited(chats=source_channels))
async def handle_edited_message(event):
    print(f"Edited message detected in {event.chat_id}: {event.message.text}")
    await client.send_message(target_channel, f"Edited Message:\n{event.message}")

# Event handler for deleted messages
@client.on(events.MessageDeleted(chats=source_channels))
async def handle_deleted_message(event):
    print(f"Message deleted in {event.chat_id}: {event.deleted_ids}")
    # Optionally notify about the deletion
    await client.send_message(target_channel, f"Message deleted in source channel.")

# Start the client and keep listening for updates
print("Bot is running and listening for changes...")
client.start()
client.run_until_disconnected()
