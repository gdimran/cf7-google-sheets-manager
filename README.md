Need to install composer in plugin root directory composer install
Then need to add google sheets id
after then need to create service account from google cloud console https://console.cloud.google.com and inside the service from key tab create auth and download the .json file 
Inside the json file there is an email like "client_email": "example@example.iam.gserviceaccount.com", make your google sheets share with editable access to this email. 
Now in setting page of dashboard, insert sheet id, upload the .json file and set api auth key and done.
