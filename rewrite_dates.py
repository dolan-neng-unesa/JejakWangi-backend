
import subprocess
import json
import sys
import tempfile
from pathlib import Path
from datetime import datetime, timedelta, timezone


def git(*args):
    return subprocess.check_output(
        ["git", *args],
        text=True
    ).strip()


def main():
    # 1. Pastikan berada di repository Git
    try:
        repo_root = Path(git("rev-parse", "--show-toplevel"))
    except subprocess.CalledProcessError:
        sys.exit("ERROR: Folder ini bukan repository Git.")

    # 2. Pastikan working tree bersih
    if git("status", "--porcelain"):
        sys.exit(
            "ERROR: Working tree belum bersih. "
            "Commit atau stash perubahan terlebih dahulu."
        )

    # 3. Ambil URL remote origin
    try:
        origin = git("remote", "get-url", "origin")
    except subprocess.CalledProcessError:
        sys.exit("ERROR: Remote origin tidak ditemukan.")

    # 4. Ambil semua commit dari seluruh refs lokal
    commits = git(
        "rev-list", "--all", "--reverse"
    ).splitlines()

    if not commits:
        sys.exit("Tidak ada commit untuk diproses.")

    total = len(commits)

    print(f"Repository : {repo_root}")
    print(f"Total commit: {total}")

    # 5. Buat backup repository
    backup = (
        Path.cwd().parent /
        f"{repo_root.name}-before-rewrite.bundle"
    )

    if backup.exists():
        sys.exit(
            f"ERROR: Backup sudah ada: {backup}\n"
            "Pindahkan atau ganti nama backup tersebut dahulu."
        )

    subprocess.run(
        ["git", "bundle", "create", str(backup), "--all"],
        check=True
    )

    subprocess.run(
        ["git", "bundle", "verify", str(backup)],
        check=True
    )

    print(f"Backup berhasil: {backup}")

    # 6. Buat rentang timestamp 7 hari
    now = datetime.now(timezone.utc)
    start = now - timedelta(days=7)

    date_map = {}

    for i, commit_hash in enumerate(commits):

        if total == 1:
            timestamp = now
        else:
            timestamp = (
                start +
                (now - start) * i / (total - 1)
            )

        epoch = int(timestamp.timestamp())

        date_map[commit_hash] = f"{epoch} +0000"

    # 7. Jalankan git-filter-repo
    with tempfile.TemporaryDirectory() as temp:

        mapping_file = Path(temp) / "dates.json"

        mapping_file.write_text(
            json.dumps(date_map)
        )

        callback = (
            "import json; "
            f"_dates=json.load(open({str(mapping_file)!r})); "
            "_date=_dates[commit.original_id.decode()]; "
            "commit.author_date=_date.encode(); "
            "commit.committer_date=_date.encode()"
        )

        subprocess.run(
            [
                "git",
                "filter-repo",
                "--force",
                "--commit-callback",
                callback
            ],
            check=True
        )

    # 8. Pulihkan remote origin jika dihapus
    remotes = git("remote").splitlines()

    if "origin" not in remotes:
        subprocess.run(
            ["git", "remote", "add", "origin", origin],
            check=True
        )

    # 9. Tampilkan hasil
    print("\n=== REWRITE SELESAI ===")
    print(f"Total commit : {total}")
    print("Rentang      : 7 hari terakhir")
    print("Timezone     : UTC")
    print("Author Date  : Diubah")
    print("Commit Date  : Diubah")
    print(f"Backup       : {backup}")

    print("\nPeriksa hasil dengan:")
    print("git log --all --format='%h | %aI | %cI | %s'")

    print("\nPush belum dilakukan.")
    print("Periksa branch dan tag sebelum melakukan push.")


if __name__ == "__main__":
    main()
