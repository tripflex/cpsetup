#!/bin/shell
cpSetup_banner() {
	cat <<"EOT"
                        ad88888ba
                       d8"     "8b              ,d
                       Y8,                      88
 ,adPPYba, 8b,dPPYba,  `Y8aaaaa,    ,adPPYba, MM88MMM 88       88 8b,dPPYba,
a8"     "" 88P'    "8a   `"""""8b, a8P_____88   88    88       88 88P'    "8a
8b         88       d8         `8b 8PP"""""""   88    88       88 88       d8
"8a,   ,aa 88b,   ,a8" Y8a     a8P "8b,   ,aa   88,   "8a,   ,a88 88b,   ,a8"
 `"Ybbd8"' 88`YbbdP"'   "Y88888P"   `"Ybbd8"'   "Y888  `"YbbdP'Y8 88`YbbdP"'
           88                                                     88
           88                                                     88
			 _                  __  __       _
			| |__  _   _    ___|  \/  |_   _| |       ___  ___
			| '_ \| | | |  / __| |\/| | | | | |      / _ \/ __|
			| |_) | |_| |  \__ \ |  | | |_| | |  _  |  __/\__ \
			|_.__/ \__, |  |___/_|  |_|\__, |_| (_)  \___||___/
			       |___/               |___/
EOT
}
#                     cPanel Server Setup & Hardening Script
# ------------------------------------------------------------------------------
# @author Myles McNamara
# @date 10.15.2014
# @version 1.0.0
# @source https://github.com/tripflex/cpsetup
# ------------------------------------------------------------------------------
# @usage ./cpsetup
# ------------------------------------------------------------------------------
# @copyright Copyright (C) 2014 Myles McNamara
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
# You should have received a copy of the GNU General Public License
# along with this program. If not, see <http://www.gnu.org/licenses/>.
# ------------------------------------------------------------------------------

# Comment out the code below to prevent script from existing on error
set -e

function headerBlock {
	l=${#1}
	printf "%s\n%s\n%s\n" "--${1//?/-}--" "- $1 -" "--${1//?/-}--"
}
function givemeayes {
	echo -n "$1 "
	read answer
	    case "$answer" in
	    Y|y|yes|YES|Yes) return 0 ;;
	    *) echo -e "\nCaptain, we hit the eject button!\n"; exit ;;
	    esac
}
clear
cpSetup_banner
givemeayes "Would you like to continue with the install? (y/n)"
yum clean all
headerBlock "Updating all system packages..."
yum update -y -q
cd ~
# --------------------------------------
# Start of Custom ClamAV Install
# --------------------------------------
headerBlock "Setting up system and downloading for ClamAV install..."
/scripts/ensurerpm gmp gmp-devel bzip2-devel
useradd clamav
groupadd clamav
mkdir /usr/local/share/clamav
chown clamav:clamav /usr/local/share/clamav
wget --no-check-certificate https://github.com/vrtadmin/clamav-devel/archive/clamav-0.98.4.tar.gz
tar -xzf clamav-*
cd clamav*
headerBlock "Building ClamAV from source..."
./configure --disable-zlib-vcheck
make
make install
headerBlock "Updating configuration files for ClamAV..."
mv -fv /usr/local/etc/freshclam.conf.sample /usr/local/etc/freshclam.conf
mv -fv /usr/local/etc/clamd.conf.sample /usr/local/etc/clamd.conf
sed -i -e 's/Example/#Example/g' /usr/local/etc/freshclam.conf
sed -i -e 's/Example/#Example/g' /usr/local/etc/clamd.conf
sed -i -e 's/#LocalSocket/LocalSocket/g' /usr/local/etc/clamd.conf
sed -i -e 's/clamd.socket/clamd/g' /usr/local/etc/clamd.conf
ldconfig
headerBlock "Updating ClamAV definition files..."
freshclam
curl http://download.configserver.com/clamd -o /etc/init.d/clamd
chown root:root /etc/init.d/clamd
chmod +x /etc/init.d/clamd
chkconfig clamd on
service clamd restart
rm -rf /etc/chkserv.d/clamav
echo "service[clamav]=x,x,x,service clamd restart,clamd,root" >> /etc/chkserv.d/clamav
touch /var/log/clam-update.log
chown clamav:clamav /var/log/clam-update.log
echo "clamav:1" >> /etc/chkserv.d/chkservd.conf
headerBlock "ClamAV installed, sock will be at /tmp/clamd"
# --------------------------------------
# Start of ConfigServer MailManage Install
# --------------------------------------
headerBlock "Installing ConfigServer MailManage..."
cd /usr/src
rm -fv /usr/src/cmm.tgz
wget http://download.configserver.com/cmm.tgz
tar -xzf cmm.tgz
cd cmm
sh install.sh
rm -Rfv /usr/src/cmm*
# --------------------------------------
# Start of ConfigServer MailQueue Install
# --------------------------------------
headerBlock "Installing ConfigServer MailQueue..."
cd ~
wget http://download.configserver.com/cmq.tgz
tar -xzf cmq.tgz
cd ~/cmq
sh install.sh
# --------------------------------------
# Start of ConfigServer Firewall Install
# --------------------------------------
headerBlock "Installing ConfigServer Firewall..."
cd ~
wget http://www.configserver.com/free/csf.tgz
tar -xzf csf.tgz
cd ~/csf
sh install.sh
# Statistical Graphs available from the csf UI
yum install perl-GDGraph
# Check perl modules
perl /usr/local/csf/bin/csftest.pl
# --------------------------------------
# Start of Malware Dection Install
# --------------------------------------
headerBlock "Installing Malware Detection..."
cd ~
wget --no-check-certificate https://www.rfxn.com/downloads/maldetect-current.tar.gz
tar -xzf maldetect-*
cd maldetect*
sh install.sh
# --------------------------------------
# Start of Server Hardening
# --------------------------------------
headerBlock "Hardening server security..."
# Check server startup for portreserve
service portreserve stop
chkconfig portreserve off