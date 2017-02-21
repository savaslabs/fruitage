# Siros

Backup all of your Harvest time entry data to CSV.

## Configuration

Set the following environment variables:

- `HARVEST_PASSWORD`
- `HARVEST_EMAIL`
- `HARVEST_ACCOUNT`

## Usage

`docker run --rm -it -v $(pwd):/data --env-file=.env savaslabs/siros backup /data/{filename}`

Or:

`php siros.php backup {filename}`

If you run without an argument, the CSV will be called `data.csv`.

The exported entries will be in slightly different order than what is provided by Harvest when you click "Export all data", because we sort time entries based on the "spent at" field, which is only granular to a particular day, whereas Harvest likely sorts their time entries by second or minute (or possibly the "created at" field).
