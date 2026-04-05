(function () {
  if (window.PandoroomLiveChatLoaded) return;
  window.PandoroomLiveChatLoaded = true;

  const API_URL = (window.PANDOROOM_CHAT_API || "https://tgbotum145.ru/telegramm/webchat_api.php").replace(/\/+$/, "");
  const STORAGE_KEY = "pandoroom_live_chat_session";
  const NAME_HIDDEN_KEY = "pandoroom_live_chat_name_hidden";

  let sessionId = localStorage.getItem(STORAGE_KEY) || "";
  let lastId = 0;
  let nameHidden = localStorage.getItem(NAME_HIDDEN_KEY) === "1";

  const root = document.createElement("div");
  root.style.cssText = "position:fixed;right:20px;bottom:20px;z-index:99999;font-family:Arial,sans-serif;";
  root.innerHTML = `
    <button id="pr-chat-toggle" style="background-image:linear-gradient(94.13deg, #ff7f01 46.63%, #edd408 74.71%, #ff7f01 100%);color:#fff;border:none;border-radius:999px;padding:12px 16px;cursor:pointer;">💬 Онлайн-чат</button>
    <div id="pr-chat-box" style="display:none;width:320px;height:380px;background:#fff;border:1px solid #ddd;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.2);margin-top:10px;overflow:hidden;">
      <div style="padding:10px;background-image:linear-gradient(94.13deg, #ff7f01 46.63%, #edd408 74.71%, #ff7f01 100%);color:#fff;font-weight:700;">Оператор Pandoroom</div>
      <div id="pr-chat-messages" style="height:230px;overflow:auto;padding:8px;background:#fafafa;"></div>
      <div style="padding:8px;border-top:1px solid #eee;">
        <input id="pr-chat-name" placeholder="Ваше имя" style="width:100%;margin-bottom:6px;padding:7px;" />
        <textarea id="pr-chat-input" rows="2" placeholder="Введите сообщение..." style="width:100%;padding:7px;"></textarea>
        <button id="pr-chat-send" style="margin-top:6px;background-image:linear-gradient(94.13deg, #ff7f01 46.63%, #edd408 74.71%, #ff7f01 100%);color:#fff;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;">Отправить</button>
      </div>
    </div>
  `;
  document.body.appendChild(root);

  const $toggle = root.querySelector("#pr-chat-toggle");
  const $box = root.querySelector("#pr-chat-box");
  const $messages = root.querySelector("#pr-chat-messages");
  const $input = root.querySelector("#pr-chat-input");
  const $name = root.querySelector("#pr-chat-name");
  const $send = root.querySelector("#pr-chat-send");

  function applyNameVisibility() {
    if (nameHidden) {
      $name.style.display = "none";
      $messages.style.height = "260px";
    } else {
      $name.style.display = "block";
      $messages.style.height = "230px";
    }
  }
  applyNameVisibility();

  function addMsg(text, mine) {
    const div = document.createElement("div");
    div.style.cssText = `margin:6px 0;display:flex;justify-content:${mine ? "flex-end" : "flex-start"};`;
    div.innerHTML = `<span style="max-width:75%;background:${mine ? "#ffecf2" : "#fff"};padding:8px 10px;border-radius:10px;border:1px solid #eee;">${String(text).replace(/[<>&]/g, s => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</span>`;
    $messages.appendChild(div);
    $messages.scrollTop = $messages.scrollHeight;
  }

  async function api(params) {
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(API_URL + "?" + qs, { method: "GET" });
    return await res.json();
  }

  async function ensureSession() {
    if (sessionId) return sessionId;
    const data = await api({ action: "init" });
    if (data && data.ok && data.session_id) {
      sessionId = data.session_id;
      localStorage.setItem(STORAGE_KEY, sessionId);
    }
    return sessionId;
  }

  async function sendMessage() {
    const text = $input.value.trim();
    if (!text) return;
    const sid = await ensureSession();
    if (!sid) return;

    addMsg(text, true);
    $input.value = "";
    if (!nameHidden) {
      nameHidden = true;
      localStorage.setItem(NAME_HIDDEN_KEY, "1");
      applyNameVisibility();
    }
    const payload = new URLSearchParams({
      action: "send",
      session_id: sid,
      name: $name.value.trim() || "Гость сайта",
      text
    });
    await fetch(API_URL, { method: "POST", body: payload });
  }

  async function poll() {
    if (!$box || $box.style.display === "none") return;
    const sid = await ensureSession();
    if (!sid) return;

    const data = await api({ action: "poll", session_id: sid, last_id: String(lastId) });
    if (!data || !data.ok || !Array.isArray(data.messages)) return;
    for (const m of data.messages) {
      addMsg(m.text || "", false);
      lastId = Math.max(lastId, Number(m.id || 0));
    }
  }

  $toggle.addEventListener("click", async () => {
    $box.style.display = $box.style.display === "none" ? "block" : "none";
    await ensureSession();
    poll();
  });
  $send.addEventListener("click", sendMessage);
  $input.addEventListener("keydown", (e) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  setInterval(poll, 3000);
})();
