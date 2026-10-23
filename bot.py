import os
import time
import requests

TOKEN = os.environ.get("BOT_TOKEN")

if not TOKEN:
    raise SystemExit("BOT_TOKEN সেট করা হয়নি!")

API = f"https://api.telegram.org/bot{TOKEN}/"


def send_message(chat_id, text):
    response = requests.post(
        API + "sendMessage",
        json={"chat_id": chat_id, "text": text},
        timeout=20
    )
    response.raise_for_status()


def main():
    offset = 0
    print("Riad10_BOT চালু হয়েছে!")

    while True:
        try:
            response = requests.get(
                API + "getUpdates",
                params={
                    "offset": offset,
                    "timeout": 25
                },
                timeout=35
            )
            response.raise_for_status()
            data = response.json()

            if not data.get("ok"):
                print("Telegram API সমস্যা:", data)
                time.sleep(3)
                continue

            for update in data.get("result", []):
                offset = update["update_id"] + 1
                message = update.get("message", {})
                chat_id = message.get("chat", {}).get("id")
                user_text = message.get("text", "").strip()

                if not chat_id or not user_text:
                    continue

                if user_text == "/start":
                    reply = (
                        "🎮 স্বাগতম Riad10_BOT-এ!\n\n"
                        "আমি GR TOPUP-এর সহায়ক বট।\n\n"
                        "/start - বট শুরু\n"
                        "/help - সাহায্য\n"
                        "/about - বট সম্পর্কে"
                    )
                elif user_text == "/help":
                    reply = (
                        "📋 সাহায্য মেনু\n\n"
                        "/start - শুরু\n"
                        "/help - সাহায্য\n"
                        "/about - বট সম্পর্কে"
                    )
                elif user_text == "/about":
                    reply = (
                        "🤖 Riad10_BOT\n"
                        "GR TOPUP-এর সহায়ক Telegram বট।"
                    )
                else:
                    reply = (
                        "✅ আপনার মেসেজ পেয়েছি!\n"
                        "সাহায্যের জন্য /help লিখুন।"
                    )

                try:
                    send_message(chat_id, reply)
                except requests.RequestException as error:
                    print("মেসেজ পাঠাতে সমস্যা:", error)

        except (requests.RequestException, ValueError) as error:
            print("সংযোগে সমস্যা:", error)
            time.sleep(3)


if __name__ == "__main__":
    main()
