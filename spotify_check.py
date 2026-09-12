"""
Spotify: find your shows and count their episodes.

Never calls .json() blindly - it prints the status code and the raw body
instead, so you always see what Spotify actually said.
"""
import base64
import requests

CID = "your_client_id"
SEC = "your_client_secret"


def get_token():
    auth = base64.b64encode(f"{CID}:{SEC}".encode()).decode()
    r = requests.post("https://accounts.spotify.com/api/token",
                      headers={"Authorization": "Basic " + auth},
                      data={"grant_type": "client_credentials"}, timeout=30)
    if r.status_code != 200:
        raise SystemExit(f"token failed: HTTP {r.status_code} {r.text[:200]}")
    return r.json()["access_token"]


HEAD = {"Authorization": "Bearer " + get_token()}


def api(url, **params):
    """GET that never explodes. Returns the dict, or None and says why."""
    r = requests.get(url, headers=HEAD, params=params, timeout=30)
    if r.status_code != 200:
        body = r.text[:200] if r.text.strip() else "(empty body)"
        print(f"    HTTP {r.status_code} - {body}")
        return None
    if not r.text.strip():
        print("    HTTP 200 but the body was empty")
        return None
    return r.json()


def find_show(name):
    """Look a show up by its title - the <title> of your feed."""
    print(f"search: {name[:60]}")
    d = api("https://api.spotify.com/v1/search",
            q=name, type="show", market="US", limit=5)
    if not d:
        return []
    items = d.get("shows", {}).get("items", [])
    if not items:
        print("    no show found with that name")
    for s in items:
        print(f"    {s['id']}  {s.get('total_episodes', '?'):>4} episodes  {s['name'][:55]}")
    return items


def count_episodes(show_id):
    """Episode count for a show id - the part after /show/ in the Spotify url."""
    d = api(f"https://api.spotify.com/v1/shows/{show_id}", market="US")
    if d is None:
        print(f"    {show_id}: not readable. An unknown show id gives a 404 with an EMPTY body,"
              f" which is what crashes .json()")
        return None
    print(f"    {d['name'][:55]} -> {d['total_episodes']} episodes")
    return d["total_episodes"]


if __name__ == "__main__":
    # 1. find the show by the title in your feed
    find_show("Creative Storytelling Performances Enriching Audiences")

    # 2. then count with the id it printed
    # count_episodes("PUT_THE_REAL_ID_HERE")
