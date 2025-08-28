Workcity chat plugin.

What is in v1.1
- Custom REST endpoint `/workcity-chat/v1/recipients/{role}` returning only `id` and `name`.
- Frontend now populates the second dropdown securely using the new endpoint.
- Session persistence: active session is saved to user meta and auto-loaded on page reload.
- Wider, more comfortable typing box CSS.
- When creating a session, plugin stores `recipient_user_id` in session meta.
- Improved error handling for empty recipient lists.

HOwto install
1. Upload `workcity-wordpress-chat-v2.zip` via WP Admin -> Plugins -> Add New -> Upload Plugin.
2. Activate plugin.
3. Ensure you have users assigned to roles `designer`, `shop_manager`, `agent`. You can create these roles using a role plugin or assign to existing roles (just use the exact role slugs).
4. Create a page and add `[workcity_chat]`.

 How persistence works
- When the user starts a chat, the plugin creates a `chat_session` and saves the session ID in the user's meta (`wc_chat_active_session`).
- On page load the shortcode checks for that meta and prints it as `data-current-session` so the frontend loads previous messages automatically.

Security notes
- The recipients endpoint only returns minimal, non-sensitive info.
- REST endpoints require authentication (logged-in users).
- For production, consider restricting who can open chats or adding rate-limiting.
