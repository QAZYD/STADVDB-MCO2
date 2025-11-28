#!/bin/bash
# =============================================================================
# Script: create_fragments.sh
# Purpose: Create horizontal fragments of the users table for distributed DB
# Node 1: users 1–50000
# Node 2: users 50001–100000
# =============================================================================

# MySQL credentials for Node 0
USER="G9_1"
PASS="pass1234"
DB="faker"
HOST="127.0.0.1"
PORT=3306

# Output folder (relative to script location)
OUTPUT_DIR="/var/www/html/myProject/"

# Fragment 1: users 1–50000
mysqldump -u $USER -p$PASS -h $HOST -P $PORT --where="id BETWEEN 1 AND 50000" $DB users > ${OUTPUT_DIR}faker_users_node1.sql

# Fragment 2: users 50001–100000
mysqldump -u $USER -p$PASS -h $HOST -P $PORT --where="id BETWEEN 50001 AND 100000" $DB users > ${OUTPUT_DIR}faker_users_node2.sql

echo "Fragments created successfully in $OUTPUT_DIR"
