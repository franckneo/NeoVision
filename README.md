apt update && apt install -y git curl

git clone https://github.com/franckneo/NeoVision.git /tmp/NeoVision && /tmp/NeoVision/install.sh


Ouvrir sur navigateur :  http://<IP_DU_SERVEUR>/
















Ajouter les PCs a la supervision :
vim /etc/fstab
Ajouter la ligne suivante :
//SRV-FS/public/folder /mnt/wupdate cifs credentials=/root/.smbcredentials,vers=3.0,uid=www-data,gid=www-data,file_mode=0775,dir_mode=0775 0 0

Remplir le fichier /root/.smbcredentials avec les informations suivantes :
username=<admin>
password=<SuperPassword>
domain=<domain.local>
