vim /etc/fstab
//SRV-FS/public/folder /mnt/wupdate cifs credentials=/root/.smbcredentials,vers=3.0,uid=www-data,gid=www-data,file_mode=0775,dir_mode=0775 0 0


cat /root/.smbcredentials
username=<admin>
password=<SuperPassword>
domain=<domain.local>
