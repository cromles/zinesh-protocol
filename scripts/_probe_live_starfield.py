import re
import urllib.request

UA = {"User-Agent": "Mozilla/5.0"}
html = urllib.request.urlopen(
    urllib.request.Request("https://www.zinesh.com/", headers=UA), timeout=25
).read().decode("utf-8", "replace")

title_m = re.search(r"<title>([^<]+)", html)
print("title:", title_m.group(1) if title_m else "n/a")

css_paths = re.findall(r"/assets/index-[^\"']+\.css", html)
js_paths = re.findall(r"/assets/main-[^\"']+\.js", html)
print("css:", css_paths)
print("js:", js_paths)

keys = ["home-starfield", "homeStarTwinkle", "homeShootingStar", "home-star-dot"]
for path in css_paths[:1] + js_paths[:1]:
    url = "https://www.zinesh.com" + path
    body = urllib.request.urlopen(
        urllib.request.Request(url, headers=UA), timeout=25
    ).read().decode("utf-8", "replace")
    print(path, {k: (k in body) for k in keys})
