#!/bin/bash

# --- Parse DB credentials from config.php ---
CONFIG="/var/www/html/myProject/config.php"

DB_USER=$(grep -oP '\$user\s*=\s*"\K[^"]+' "$CONFIG")
DB_PASS=$(grep -oP '\$pass\s*=\s*"\K[^"]+' "$CONFIG")
DB_NAME=$(grep -oP '\$db\s*=\s*"\K[^"]+' "$CONFIG")

echo "Using DB credentials:"
echo "User: $DB_USER"
echo "DB:   $DB_NAME"
echo ""

# --- File paths ---
NODE1_SQL="/var/www/html/myProject/Node1/Users_node1.sql"
NODE2_SQL="/var/www/html/myProject/Node2/Users_node2.sql"

# --- Import functions ---
import_sql() {
    FILE=$1
    echo "Importing $FILE ..."
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$FILE"

    if [ $? -eq 0 ]; then
        echo "✔ Successfully imported $FILE"
    else
        echo "✖ Failed to import $FILE"
    fi

    echo ""
}

# --- Run imports ---
import_sql "$NODE1_SQL"
import_sql "$NODE2_SQL"

echo "All imports finished."
