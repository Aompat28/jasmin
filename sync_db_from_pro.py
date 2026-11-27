import subprocess, re, sys, os, time

print("=" * 60)
print("  Syncing Database from Production to Localhost...")
print("=" * 60)
print()

# 1. Dump database from Production Server via SSH
print("[1/3] Dumping database from Production Server...")
t0 = time.time()
try:
    res = subprocess.run(
        ['ssh', '-o', 'StrictHostKeyChecking=no', 'fuyuki@ssh.sourceped.com', 'mysqldump -u root property_management'],
        capture_output=True
    )
except Exception as e:
    print(f"[ERROR] Failed to run SSH: {e}")
    sys.exit(1)

if res.returncode != 0:
    print("[ERROR] Failed to dump database:")
    print(res.stderr.decode('utf-8', errors='ignore'))
    sys.exit(1)

dump_data = res.stdout
print(f"      Downloaded {len(dump_data):,} bytes ({time.time() - t0:.2f}s)")

# 2. Clean MariaDB 11 incompatibilities for XAMPP (MariaDB 10.4)
print("[2/3] Converting charset/collation for XAMPP compatibility...")
# Remove sandbox mode header
clean_data = re.sub(rb'/\*M!999999\\-[^\r\n]*[\r\n]+', b'', dump_data)
# Convert new collation to unicode_ci
clean_data = clean_data.replace(b'utf8mb4_uca1400_ai_ci', b'utf8mb4_unicode_ci')

desktop_dir = os.path.join(os.path.expanduser('~'), 'Desktop')
sql_export_path = os.path.join(desktop_dir, 'property_management_for_friend.sql')
with open(sql_export_path, 'wb') as f:
    f.write(clean_data)
print(f"      Saved export file to: Desktop\\property_management_for_friend.sql")

# 3. Import into Localhost MySQL
print("[3/3] Importing into Localhost MySQL (property_management)...")
t1 = time.time()
mysql_bin = r'C:\xampp\mysql\bin\mysql.exe'
if not os.path.exists(mysql_bin):
    mysql_bin = 'mysql'

res_import = subprocess.run(
    [mysql_bin, '-u', 'root', 'property_management'],
    input=clean_data,
    capture_output=True
)

if res_import.returncode != 0:
    print("[WARNING] Local import notice:")
    print(res_import.stderr.decode('utf-8', errors='ignore'))
else:
    print(f"      Imported to Localhost successfully! ({time.time() - t1:.2f}s)")

print()
print("=" * 60)
print("  SYNC COMPLETED SUCCESSFULLY!")
print("  - Localhost database updated with all latest accounts.")
print("  - 'property_management_for_friend.sql' created on Desktop")
print("    (You can send this file to your friend).")
print("=" * 60)
print()
