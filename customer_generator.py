#!/usr/local/bin/python3
import csv
import random
import sys
import uuid
from datetime import date
from calendar import monthrange

WEBSITE = "base"
STORE = "default"
CREATED_IN = '"Default Store View"'
PASSWORD = ""
GROUP_ID = "0"

# Get number of customers to generate
if len(sys.argv) > 1:
    try:
        NUMBER_OF_CUSTOMERS = int(sys.argv[1])
    except:
        print("Invalid argument, is it an integer?")
        exit(1)
else:
    NUMBER_OF_CUSTOMERS = input("Enter number of customers to generate: ")
    while True:
        try:
            NUMBER_OF_CUSTOMERS = int(NUMBER_OF_CUSTOMERS)
            break
        except:
            NUMBER_OF_CUSTOMERS = input("Enter number of customers to generate: ")
    
# Create and write data to file
csv.writer
with open('magento_example_customers.csv', 'w', newline='') as csvfile:
    customer_writer = csv.writer(csvfile, delimiter=',',
                            quotechar='', quoting=csv.QUOTE_NONE)
    # Write header row
    customer_writer.writerow(["email", "_website", "_store", "created_at", "created_in", "firstname", "lastname", "password", "group_id"])
    # Create each "customer," independently
    for i in range(NUMBER_OF_CUSTOMERS):
        # Create random date data
        year = random.randint(2018, date.today().year)
        month = random.randint(1, 12)
        day = str(random.randint(1, monthrange(year, month)[1])).zfill(2)
        month = str(month).zfill(2)
        created_at = f'"{year}-{month}-{day} 00:00:00"' 
        # Generate unique ID for email address
        email = str(uuid.uuid1().hex) + "@example.com"
        firstname = "John" + str(i)
        lastname = "Doe" + str(i)
        # Write customer data to file
        customer_writer.writerow([email, WEBSITE, STORE, created_at, CREATED_IN, firstname, lastname, PASSWORD, GROUP_ID])

    print("Data written to 'magento_example_customers.csv'")

