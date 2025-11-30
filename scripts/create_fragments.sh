#!/bin/bash
# =============================================================================
# Script: create_fragments.sh
# Purpose: Create horizontal fragments of the Users table for distributed DB
# Node 1: Users 1–50000
# Node 2: Users 50001–100000
# =============================================================================

# MySQL credentials for Node 0
USER="G9_1"
PASS="pass1234"
DB="faker"
HOST="127.0.0.1"
PORT=3306

# Base output folder (relative to script location)
BASE_DIR="/var/www/html/myProject"

# Create Node folders if they don't exist
mkdir -p "${BASE_DIR}/Node1"
mkdir -p "${BASE_DIR}/Node2"

# Fragment 1: Users 1–50000 → Node1 folder
mysqldump -u $USER -p$PASS -h $HOST -P $PORT --where="id BETWEEN 1 AND 50000" $DB Users > "${BASE_DIR}/Node1/Users_node1.sql"

# Fragment 2: Users 50001–100000 → Node2 folder
mysqldump -u $USER -p$PASS -h $HOST -P $PORT --where="id BETWEEN 50001 AND 100000" $DB Users > "${BASE_DIR}/Node2/Users_node2.sql"

echo "Fragments created successfully:"
echo "Node1 → ${BASE_DIR}/Node1/Users_node1.sql"
echo "Node2 → ${BASE_DIR}/Node2/Users_node2.sql"
