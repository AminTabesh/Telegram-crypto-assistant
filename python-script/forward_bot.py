from telethon import TelegramClient, events


api_id = '15338268'
api_hash = '6e0c5aa5df9a760e77ce9306320423b7'


source_channels = ['@nonadminchanneld'] 
target_channel = '@DirectamCryptobot'


client = TelegramClient('channel_watch', api_id, api_hash)


@client.on(events.NewMessage(chats=source_channels))
async def handle_new_message(event):
    print(f"New message detected in {event.chat_id}: {event.message.text}")
    await client.send_message(target_channel, event.message)


@client.on(events.MessageEdited(chats=source_channels))
async def handle_edited_message(event):
    print(f"Edited message detected in {event.chat_id}: {event.message.text}")
    await client.send_message(target_channel, f"Edited Message:\n{event.message}")


@client.on(events.MessageDeleted(chats=source_channels))
async def handle_deleted_message(event):
    print(f"Message deleted in {event.chat_id}: {event.deleted_ids}")

    await client.send_message(target_channel, f"Message deleted in source channel.")


print("Bot is running and listening for changes...")
client.start()
client.run_until_disconnected()
